<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\ExternalAccess\Controller;

use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;
use Bitrix\Note\Infrastructure\Controller\ErrorCodeDictionary;
use Bitrix\Note\Internal\Integration\Disk\DiskGateway;
use Bitrix\Note\Internal\Model\DocumentTable;
use Bitrix\Note\Internal\Repository\DocumentFileLinkRepository;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Service\DocumentFileService;
use Bitrix\Note\Internal\Service\File\DiskAttachmentResolver;
use Bitrix\Note\Internal\Service\ExternalAccess\PublicMentionService;
use Bitrix\Note\Internal\Service\ExternalAccess\PublicResponseMapper;
use Bitrix\Note\Internal\Service\NoteFileUrlService;

/**
 * The description of the knowledge base a public link leads to.
 *
 * The reading action takes no parameters at all: the knowledge base is the one from the link context,
 * and an identifier accepted from the outside would only add a path along which knowledge bases can
 * be probed. Its own id is not in the answer either, and neither is the id of the document that
 * carries the text - for a visitor that document does not exist: the scoped reader refuses it like
 * anything else outside the scope, and the description is the only thing of it that is public. The
 * files used inside the description are resolved the same way, by their own action below, so that the
 * carrier document never has to be named from the outside either.
 *
 * The text is read the way an ordinary document is read, and by the same mapper: `yjsState` plus the
 * journal that has not been folded into it yet for a description under collaborative editing,
 * `markdown` otherwise. The stored `MARKDOWN` column is not taken for a collaborative description on
 * purpose - it is a projection rebuilt by a background agent and lags behind the text a visitor is
 * being shown: what matters is freshness, not the last projection.
 */
class DescriptionController extends BaseController
{
	/**
	 * The ceiling of one batch, the same reason as in the public FileController: describing files is
	 * not free, and here it also bounds how many ids one request may test.
	 */
	private const RESOLVE_MAX_ITEMS = 200;

	public function __construct(
		?HttpRequest $request = null,
		private readonly DocumentRepository $repository = new DocumentRepository(),
		private readonly PublicResponseMapper $mapper = new PublicResponseMapper(),
	)
	{
		parent::__construct($request);
	}

	/**
	 * A knowledge base without a description answers with an empty text and not with a refusal:
	 * "there is no description" and "there is no such base" are not told apart, and for the client
	 * both mean the same empty screen.
	 *
	 * @return array{
	 *     contentFormat: string,
	 *     updatedAt: ?string,
	 *     yjsState?: string,
	 *     markdown?: array|string,
	 *     collaboration?: array{patches: array<int, array{patch: string}>},
	 * }
	 */
	public function getAction(): array
	{
		$description = $this->repository->getLiveMainInCollection(
			$this->getExternalAccessContext()->getCollectionId(),
		);
		if ($description === null)
		{
			return [
				'contentFormat' => DocumentTable::CONTENT_FORMAT_MD,
				'updatedAt' => null,
				'markdown' => '',
			];
		}

		// The identity of the carrier document stays out of the answer; only its content does not.
		return $this->mapper->mapContent($description);
	}

	/**
	 * Addresses of the module's own files used inside the description.
	 *
	 * An action of its own and not resolveFileUrlsBatch: that one is asked for a document, and the
	 * document carrying the description is the one thing the scope must never let out - a visitor
	 * cannot name it, and it would be an answer to "does this knowledge base have a description
	 * document, and what is its id".
	 *
	 * Security. The list of ids comes from an anonymous visitor, so the action is a probe by
	 * construction: asked about a file it must not confirm anything about ids that are not ours. What
	 * makes an id ours is the link table - the file has to be linked to this very description document
	 * (DocumentFileLinkRepository::getByDocumentAndFileIds), and being linked to any other document of
	 * the portal, or to nothing at all, is the same answer. Every refusal therefore carries one and the
	 * same reason: "no such file here" and "there is such a file but it is not yours" told apart is the
	 * leak itself.
	 *
	 * @return array{files: array[], failed: array[]}
	 */
	public function resolveFilesAction(array $fileIds): array
	{
		if (empty($fileIds))
		{
			return ['files' => [], 'failed' => []];
		}

		// Only the id of the carrier document is wanted here - this batch describes files and never
		// shows the text - so the text is not read.
		$descriptionId = $this->repository->getLiveMainIdInCollection(
			$this->getExternalAccessContext()->getCollectionId(),
		);
		if ($descriptionId === null)
		{
			// A knowledge base without a description owns no files, and that is not explained either.
			return ['files' => [], 'failed' => $this->buildFailures($fileIds)];
		}

		// The batch is capped rather than trusted: an uncapped list is a cheap way to sweep the file
		// ids of the portal in one request.
		$requestedIds = array_slice(array_values($fileIds), 0, self::RESOLVE_MAX_ITEMS);

		$linkedFileIds = [];
		foreach ((new DocumentFileLinkRepository())->getByDocumentAndFileIds($descriptionId, $requestedIds) as $link)
		{
			$linkedFileIds[(int)$link['FILE_ID']] = true;
		}

		$documentFileService = new DocumentFileService();
		// The whole set is read at once: asking file by file turned a batch of two hundred into two
		// hundred lookups on a circuit anyone may call.
		$validatedFiles = $documentFileService->getValidatedNoteFilesMap($requestedIds);
		$files = [];
		$failed = [];
		foreach ($requestedIds as $rawFileId)
		{
			$fileId = (int)$rawFileId;
			// An id that is not an id at all is refused like any other: the answer must depend on what
			// was asked no more than it has to, and a silently dropped entry is a difference of shape
			// that a caller can read.
			if ($fileId <= 0 || !isset($linkedFileIds[$fileId]) || !isset($validatedFiles[$fileId]))
			{
				$failed[] = $this->buildFailure($fileId);

				continue;
			}

			$payload = $documentFileService->buildFilePayload(
				$fileId,
				showUrl: NoteFileUrlService::createPublicShowUrl($fileId),
				downloadUrl: NoteFileUrlService::createPublicDownloadUrl($fileId),
				fileData: $validatedFiles[$fileId],
				previewUrl: NoteFileUrlService::createPublicShowUrl($fileId, preview: true),
			);
			if ($payload === null)
			{
				$failed[] = $this->buildFailure($fileId);

				continue;
			}

			$files[] = $payload;
		}

		return ['files' => $files, 'failed' => $failed];
	}

	/**
	 * Attributes of the Disk files used inside the description: name, size, links, viewer attributes.
	 *
	 * An action of its own for the reason resolveFiles has one - the carrier document may not be named
	 * from the outside - and it exists at all because a description is written by the ordinary editor,
	 * where a file of Disk is inserted like any other. Without it those blocks stayed in the loading
	 * state on a public page forever: the markdown of such a block carries nothing but the object id,
	 * and everything needed to draw it is asked of the server.
	 *
	 * Security. The resolver answers about an object on one ground here - it is attached to this very
	 * description - because the second ground it knows, a right of the reader on the file, belongs to
	 * nobody in this circuit. So an object of any other document, or of no document, is "unavailable"
	 * with no name, size or link, exactly like an id that does not exist. Disk's quick-access
	 * descriptor is cut out of the addresses for the same reason as in the public FileController: it
	 * is a pass to the bytes that outlives a revoke by about a week.
	 *
	 * @return array{items: array[]}
	 */
	public function resolveDiskAttachmentsAction(array $objectIds): array
	{
		if (empty($objectIds))
		{
			return ['items' => []];
		}

		$descriptionId = $this->repository->getLiveMainIdInCollection(
			$this->getExternalAccessContext()->getCollectionId(),
		);
		if ($descriptionId === null)
		{
			// A knowledge base without a description references no file, and that is not explained.
			return ['items' => []];
		}

		$resolver = new DiskAttachmentResolver(new DiskGateway(quickAccessAllowed: false));

		return [
			'items' => $resolver->resolve(
				$descriptionId,
				array_slice(array_values($objectIds), 0, self::RESOLVE_MAX_ITEMS),
			),
		];
	}

	/**
	 * The mentions written inside the description, as a visitor may know them.
	 *
	 * An action of its own for the same reason as resolveFiles: `MentionController::listByDocument`
	 * is asked for a document, and the document carrying the description is the one a visitor may
	 * never name. The list of mentions comes from the client here - it is the only side that holds
	 * the live text of the description, whose stored MARKDOWN column is a lagging projection.
	 *
	 * Security. A document is resolved inside the scope of the link only, and outside the scope,
	 * archived, in the recycle bin or never existing are one and the same answer - the title is what
	 * tells that someone else's document exists, so it is not built at all. Employees, knowledge
	 * bases and tasks are refused without any lookup: those live behind the authentication a visitor
	 * does not have, and a name of an employee is exactly what must not leave the portal.
	 *
	 * @param array<array{type: string, id: int}> $items
	 * @return array{mentions: array[], isCapped: bool}
	 */
	public function listMentionsAction(array $items): array
	{
		// The carrier document is not asked about here, unlike in resolveFiles: a file has to belong
		// to the description, while a mention is answered by the scope of the link alone. Refusing
		// everything for a knowledge base without a description would add nothing but a difference in
		// the answer that says the description is missing.
		return (new PublicMentionService($this->getExternalAccessContext()))->listRequested($items);
	}

	/**
	 * @return array<int, array{fileId: int, reason: string, message: string}>
	 */
	private function buildFailures(array $fileIds): array
	{
		$failed = [];
		foreach (array_slice(array_values($fileIds), 0, self::RESOLVE_MAX_ITEMS) as $rawFileId)
		{
			$failed[] = $this->buildFailure((int)$rawFileId);
		}

		return $failed;
	}

	/**
	 * @return array{fileId: int, reason: string, message: string}
	 */
	private function buildFailure(int $fileId): array
	{
		$code = ErrorCodeDictionary::EXTERNAL_ACCESS_NOT_FOUND;

		return [
			'fileId' => $fileId,
			// One reason for every refusal - see the security note on the action.
			'reason' => 'not_found',
			'message' => (string)Loc::getMessage($code),
		];
	}
}
