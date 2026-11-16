<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\ExternalAccess\Controller;

use Bitrix\Main\Engine\ActionFilter\CloseSession;
use Bitrix\Main\Engine\ActionFilter\Csrf;
use Bitrix\Main\Engine\ActionFilter\HttpMethod;
use Bitrix\Main\Engine\ActionFilter\Scope;
use Bitrix\Main\Engine\Response\BFile;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Note\Infrastructure\Controller\ErrorCodeDictionary;
use Bitrix\Note\Infrastructure\Controller\FileController as InternalFileController;
use Bitrix\Note\Infrastructure\ExternalAccess\Controller\ActionFilter\ExternalAccessVisitorContext;
use Bitrix\Note\Internal\Integration\Disk\DiskGateway;
use Bitrix\Note\Internal\Repository\DocumentFileLinkRepository;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Service\DocumentFileService;
use Bitrix\Note\Internal\Service\File\DiskAttachmentResolver;
use Bitrix\Note\Internal\Service\File\ImagePreviewService;
use Bitrix\Note\Internal\Service\NoteFileUrlService;

/**
 * Files of a document opened by a public link: the module's own ones and the attachments of Disk.
 *
 * The scheme of the ordinary FileController is repeated step by step - file to its document, document
 * to its knowledge base - and only the last step differs: where the ordinary circuit computes the
 * rights of a user, this one compares the knowledge base with the scope of the link. Nothing that the
 * scope does not contain is told apart from what does not exist.
 *
 * None of the four actions takes a token: the visitor is recognised by the marks of their
 * session, and the reason is different for the two halves of the set.
 *
 * `show` and `download` are GET and have no Csrf: their address goes into `<img src>`, `<video src>`
 * and into the download link, and a browser sends neither a session id nor a request body there - a
 * POST under Csrf is simply unreachable from a picture. A token in the address would be worse still:
 * it would carry the secret of the link into the browser history, the log of the web server and the
 * referer of the next request.
 *
 * The two batches stay POST under Csrf - the application calls them itself, and there is nothing to
 * relax. They ask for no token either, for the same reason the other two do not: the marks of the
 * session already name the visitor. The client does put one into the body of every public request -
 * it takes the token out of `routerBase` - but nothing here reads it.
 *
 * The ordinary FileController is not touched by any of this.
 */
class FileController extends BaseController
{
	/**
	 * The ceiling of the ordinary circuit, repeated for the same reason and shared by both batches:
	 * describing a file is not cheap, the caller here is anonymous, and the size of a batch is bounded
	 * instead of being trusted.
	 */
	private const RESOLVE_MAX_ITEMS = 200;

	/**
	 * Media served inline; everything else is served as an attachment.
	 *
	 * A picture and a video have to be readable by the tag that shows them, and an attachment
	 * disposition breaks seeking in a video. Everything else is forced to download on purpose: a file
	 * of a knowledge base is content someone uploaded, and rendering, say, an html or an svg inline
	 * would run it as a page of this portal - to an anonymous visitor, on the portal's own domain.
	 */
	private const INLINE_MIME_PREFIXES = ['image/', 'video/', 'audio/'];

	/**
	 * The set is overridden whole rather than extended: the token filter of the base has nothing to
	 * read here, and the context comes from the marks of the visitor session instead.
	 */
	protected function getDefaultPreFilters(): array
	{
		return [
			new HttpMethod([HttpMethod::METHOD_POST]),
			new Csrf(),
			new Scope(Scope::AJAX),
			new ExternalAccessVisitorContext(),
		];
	}

	public function configureActions(): array
	{
		$streamFilters = [
			'-prefilters' => [
				Csrf::class,
				HttpMethod::class,
			],
			'+prefilters' => [
				new HttpMethod([HttpMethod::METHOD_GET]),
				// The file is streamed while the session is held open otherwise: a page with a dozen
				// pictures would serialise them all on one lock.
				new CloseSession(),
			],
		];

		return [
			'show' => $streamFilters,
			'download' => $streamFilters,
		];
	}

	/**
	 * The bytes of the file, for a tag that shows it; with `preview` a large picture is served as its
	 * reduced copy.
	 */
	public function showAction(int $fileId, bool $preview = false): ?BFile
	{
		return $this->streamFile($fileId, inline: true, preview: $preview);
	}

	/**
	 * The same file as a download. The ordinary circuit answers a resolveDownload POST with an
	 * address; a visitor has nowhere to POST from - the download is a plain link in the page - so the
	 * public circuit serves the file itself.
	 */
	public function downloadAction(int $fileId): ?BFile
	{
		return $this->streamFile($fileId, inline: false);
	}

	/**
	 * Addresses of the module's own files of one document, in the shape of the ordinary
	 * batch: the client parses attachments with one and the same code in both circuits.
	 *
	 * Nothing is healed here, unlike in the ordinary batch: adopting a file borrowed from another
	 * document is a write, and a visitor writes nothing. A file that is not linked to this document
	 * is reported as failed exactly as it is to a reader without the right to edit.
	 *
	 * @return array{files: array[], failed: array[]}|null
	 */
	public function resolveFileUrlsBatchAction(int $documentId, array $fileIds): ?array
	{
		if ($documentId <= 0 || empty($fileIds))
		{
			return ['files' => [], 'failed' => []];
		}

		if (!$this->isDocumentInScope($documentId))
		{
			return null;
		}

		$fileIds = array_slice($fileIds, 0, self::RESOLVE_MAX_ITEMS);

		$linkRepository = new DocumentFileLinkRepository();
		$documentFileService = new DocumentFileService();

		$linkedFileIds = [];
		foreach ($linkRepository->getByDocumentAndFileIds($documentId, $fileIds) as $link)
		{
			$linkedFileIds[(int)$link['FILE_ID']] = true;
		}

		// The whole set is read at once: asking file by file turned a batch of two hundred into two
		// hundred lookups on a circuit anyone may call.
		$validatedFiles = $documentFileService->getValidatedNoteFilesMap($fileIds);

		$files = [];
		$failed = [];
		foreach ($fileIds as $rawFileId)
		{
			$fileId = (int)$rawFileId;
			if ($fileId <= 0)
			{
				continue;
			}

			if (!isset($linkedFileIds[$fileId]))
			{
				$failed[] = $this->buildFailure($fileId, 'not_linked');

				continue;
			}

			if (!isset($validatedFiles[$fileId]))
			{
				$failed[] = $this->buildFailure($fileId, 'file_source');

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
				$failed[] = $this->buildFailure($fileId, 'not_found');

				continue;
			}

			$files[] = $payload;
		}

		return ['files' => $files, 'failed' => $failed];
	}

	/**
	 * Attributes of the Disk attachments of one document, in the shape of the ordinary batch.
	 *
	 * The public twin exists because the mount payload announces `diskAttachmentsEnabled: true` on every mount:
	 * without an action of its own the client would call the ordinary one and be refused.
	 *
	 * The addresses are built with Disk's quick-access descriptor removed: that descriptor
	 * is a pass to the bytes that outlives a revoke by about a week.
	 *
	 * @return array{items: array[]}|null
	 */
	public function resolveDiskAttachmentsBatchAction(int $documentId, array $objectIds): ?array
	{
		if ($documentId <= 0 || empty($objectIds))
		{
			return ['items' => []];
		}

		if (!$this->isDocumentInScope($documentId))
		{
			return null;
		}

		$resolver = new DiskAttachmentResolver(new DiskGateway(quickAccessAllowed: false));

		return [
			'items' => $resolver->resolve(
				$documentId,
				array_slice($objectIds, 0, self::RESOLVE_MAX_ITEMS),
			),
		];
	}

	/**
	 * The scope is checked here and not trusted from the filter's chain: the filter found the link by
	 * the knowledge base of this very document, but whether the document is still alive, unarchived
	 * and reachable inside the scope is the question of the scoped reader, and it is the only one
	 * allowed to answer it.
	 */
	private function streamFile(int $fileId, bool $inline, bool $preview = false): ?BFile
	{
		if ($fileId <= 0)
		{
			return $this->refuseAsMissing();
		}

		// The pre-filter has just walked "file -> document -> knowledge base -> link" to let this
		// request in, so the row it read on the way is handed over with the context instead of being
		// read again. Its file has to be the one being served - the filter resolved the link from the
		// same request, and a row about any other file is simply not used.
		$link = $this->getExternalAccessContext()->getFileLink();
		if ($link === null || (int)($link['FILE_ID'] ?? 0) !== $fileId)
		{
			$link = (new DocumentFileLinkRepository())->getLinkByFileId($fileId);
		}

		$documentId = (int)($link['DOCUMENT_ID'] ?? 0);
		$linkedFileId = (int)($link['FILE_ID'] ?? 0);
		if ($documentId <= 0 || $linkedFileId <= 0)
		{
			return $this->refuseAsMissing();
		}

		// The scope never returns the hidden main document - it is what carries the description, and a
		// visitor must not be able to open it or learn its id. Its files are another matter: the
		// description itself is public, so a picture inside it is public with it. The exception is
		// resolved from the link context alone (see isDescriptionDocument), so nothing a request can
		// say chooses the document it applies to.
		if (
			!$this->getScopedDocumentReader()->hasDocument($documentId)
			&& !$this->isDescriptionDocument($documentId)
		)
		{
			return $this->refuseAsMissing();
		}

		$fileData = (new DocumentFileService())->getValidatedNoteFile($linkedFileId);
		if ($fileData === null)
		{
			return $this->refuseAsMissing();
		}

		$inline = $inline && $this->isInlineMime((string)($fileData['CONTENT_TYPE'] ?? ''));
		if ($preview)
		{
			return (new ImagePreviewService())->createResponse($fileData, $inline);
		}

		$file = BFile::createByFileId($linkedFileId);
		$file->setCacheTime(InternalFileController::SHOW_CACHE_TIME);
		$file->showInline($inline);

		return $file;
	}

	private function isInlineMime(string $contentType): bool
	{
		$contentType = mb_strtolower($contentType);
		foreach (self::INLINE_MIME_PREFIXES as $prefix)
		{
			if (str_starts_with($contentType, $prefix))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * The one document outside the scope whose files a visitor may still read: the hidden main document
	 * of the knowledge base of this link, the carrier of the description. The description itself is
	 * public (see DescriptionController), and a picture inside it that cannot be fetched makes it half
	 * a description.
	 *
	 * The document is resolved from the link context and never from a parameter, so this widens what
	 * may be read by exactly one document per link and gives no way to name any other. The id is not
	 * told to anyone either - it is compared here and stays here.
	 */
	private function isDescriptionDocument(int $documentId): bool
	{
		if ($documentId <= 0)
		{
			return false;
		}

		$descriptionId = (new DocumentRepository())->getLiveMainIdInCollection(
			$this->getExternalAccessContext()->getCollectionId(),
		);

		return $descriptionId !== null && $descriptionId === $documentId;
	}

	private function isDocumentInScope(int $documentId): bool
	{
		if ($this->getScopedDocumentReader()->hasDocument($documentId))
		{
			return true;
		}

		$this->refuseAsMissing();

		return false;
	}

	/**
	 * One answer for everything the scope does not contain, missing or broken: beyond the scope of a
	 * link nothing may be told apart from what does not exist at all.
	 */
	private function refuseAsMissing(): null
	{
		$code = ErrorCodeDictionary::EXTERNAL_ACCESS_NOT_FOUND;
		$this->addError(new Error(Loc::getMessage($code), $code));

		return null;
	}

	/**
	 * @return array{fileId: int, reason: string, message: string}
	 */
	private function buildFailure(int $fileId, string $reason): array
	{
		return [
			'fileId' => $fileId,
			'reason' => $reason,
			'message' => (string)Loc::getMessage(ErrorCodeDictionary::EXTERNAL_ACCESS_NOT_FOUND),
		];
	}
}
