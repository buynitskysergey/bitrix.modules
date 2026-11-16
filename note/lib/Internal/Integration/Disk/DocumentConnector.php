<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Integration\Disk;

use Bitrix\Disk\Uf\Connector;
use Bitrix\Note\Internal\Access\Service\DocumentAccessService;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Repository\RecycleBinRepository;
use Bitrix\Note\Internal\Service\ExternalAccess\PublicVisitorContextResolver;

/**
 * Tells Disk who may see a file attached to a note document.
 *
 * The verdict is about the DOCUMENT, never about the file: a reader of the document opens its
 * attachments even without any rights on the file itself. That is the whole point of attaching
 * rather than linking, and it is why Disk asks the owner entity instead of checking the file.
 *
 * Disk resolves this class from `AttachedObject::getEntityType()` — it stores the connector class
 * name, not the registry key — and instantiates it with the document id.
 *
 * A visitor of a public link reaches an attachment by the legacy Disk address
 * (`/bitrix/tools/disk/uf.php`), past the `note.external.*` frame and its pre-filter, so both marks
 * of the visitor session are asked here instead.
 */
final class DocumentConnector extends Connector
{
	/**
	 * Registry key under which Disk knows this connector: the value passed to
	 * `UserFieldManager::getConnectorDataByEntityType()`.
	 *
	 * Frozen after the first release — it is baked into every user field created since.
	 */
	public const ENTITY_TYPE = 'note_document';

	/**
	 * Verdicts already given in this request, keyed `documentId:userId:level:linkRecordId`.
	 *
	 * Disk builds a connector per attached object and asks each one separately, so a document with 25
	 * Disk files asks the same question 25 times. Measured for a reader who is not a portal
	 * administrator: 25 verdicts cost 50 queries of the access service and around 240 ms, against two
	 * queries and a tenth of that for one. Rights do not change inside a request, so the first answer
	 * stands for the rest of it.
	 *
	 * @var array<string, bool>
	 */
	private static array $verdicts = [];

	/**
	 * The link record a visitor of a public link reads this document by, per document, 0 for none.
	 *
	 * Same reason as the verdicts above - Disk asks per attached object - but a step earlier: without
	 * it a document with 25 Disk files would walk the whole chain "document -> knowledge base -> marks
	 * of the session -> record of the link" 25 times, and the chain reads the database twice.
	 *
	 * @var array<int, int>
	 */
	private static array $publicLinkRecordIds = [];

	/**
	 * Two grounds, and a user id decides only which of them can apply. An employee who is signed in
	 * reads a public link exactly like anybody else - nothing in the public circuit asks who they
	 * are - so a document open to them by the link has to lend its attachments too: refusing the
	 * files of a page the employee is reading would leave the link working worse for a colleague
	 * than for a stranger.
	 */
	public function canRead($userId): bool
	{
		$userId = (int)$userId;

		return ($userId > 0 && $this->hasLevel($userId, DocumentAccessService::LEVEL_VIEW))
			|| $this->hasPublicLinkRead()
		;
	}

	/**
	 * A visitor of a public link writes nothing: this release issues the view level only, and the
	 * question is answered no before anything is looked up.
	 */
	public function canUpdate($userId): bool
	{
		$userId = (int)$userId;

		return $userId > 0 && $this->hasLevel($userId, DocumentAccessService::LEVEL_EDIT);
	}

	/**
	 * Disk asks this before it asks anything about a zero user, and the base connector answers no.
	 * Ours has to say yes: a visitor of a public link is exactly a reader without a user, and the
	 * verdict for them is given by canRead() below - the permission is not widened here, only the
	 * question is allowed to be asked.
	 */
	public function isAnonymousAllowed(): bool
	{
		return true;
	}

	/**
	 * Test seam. Rights are rewritten between tests and document ids are reused across test classes, so
	 * a verdict remembered for the whole process would answer for the wrong document.
	 */
	public static function resetVerdicts(): void
	{
		self::$verdicts = [];
		self::$publicLinkRecordIds = [];
	}

	private function hasLevel(int $userId, int $requiredLevel): bool
	{
		$documentId = (int)$this->entityId;
		if ($documentId <= 0)
		{
			return false;
		}

		$key = $this->buildVerdictKey($documentId, $userId, $requiredLevel, 0);
		self::$verdicts[$key] ??= $this->computeLevel($documentId, $userId, $requiredLevel);

		return self::$verdicts[$key];
	}

	/**
	 * The verdict a public link gives, whoever is asking: the link and the document decide it, the
	 * user does not, so the key of the verdict carries no user id either.
	 *
	 * The link is part of that key and not just of its computation: every visitor has the same zero
	 * user id, so without it the answer given for one link would be reused for another inside the
	 * same request - two knowledge bases opened by one browser is the ordinary case, not a corner one.
	 */
	private function hasPublicLinkRead(): bool
	{
		$documentId = (int)$this->entityId;
		if ($documentId <= 0)
		{
			return false;
		}

		$linkRecordId = $this->resolvePublicLinkRecordId($documentId);
		$key = $this->buildVerdictKey($documentId, 0, DocumentAccessService::LEVEL_VIEW, $linkRecordId);
		self::$verdicts[$key] ??= $linkRecordId > 0;

		return self::$verdicts[$key];
	}

	/**
	 * The chain "document -> knowledge base -> record of the link" is walked by the shared resolver,
	 * which asks both marks of the visitor session: the context one and the confirmation of the
	 * password. Both, because the context mark is set when a password-protected link resolves, long
	 * before the password is named - without the second question a visitor who never typed it would
	 * open the attachments of the document while the document itself stayed closed.
	 *
	 * Being inside the scope needs no separate check - the record was found BY the knowledge base of
	 * this document - but being readable at all does: an archived and a recycled document are refused
	 * by the same reader the public actions use.
	 *
	 * The hidden main document is the one exception, and the same one FileController::streamFile
	 * already makes: it carries the description of the knowledge base, which the link publishes in
	 * full - its text by DescriptionController::getAction and its own files by resolveFiles. A file of
	 * Disk inserted into a description is that same content, and without this it was the only part of
	 * a published description a visitor could not see. The exception widens the scope by exactly one
	 * document per link and gives no way to name it: the carrier is resolved from the knowledge base
	 * of the link, never from a request.
	 */
	private function resolvePublicLinkRecordId(int $documentId): int
	{
		if (isset(self::$publicLinkRecordIds[$documentId]))
		{
			return self::$publicLinkRecordIds[$documentId];
		}

		$link = (new PublicVisitorContextResolver())->forDocument($documentId);
		$isReadable = $link !== null && $this->isReadableInPublicScope($link->collectionId, $documentId);

		return self::$publicLinkRecordIds[$documentId] = $isReadable ? $link->linkRecordId : 0;
	}

	private function isReadableInPublicScope(int $collectionId, int $documentId): bool
	{
		$repository = new DocumentRepository();

		return $repository->existsLiveInCollection($collectionId, $documentId)
			|| $repository->getLiveMainIdInCollection($collectionId) === $documentId
		;
	}

	private function buildVerdictKey(int $documentId, int $userId, int $requiredLevel, int $linkRecordId): string
	{
		return $documentId . ':' . $userId . ':' . $requiredLevel . ':' . $linkRecordId;
	}

	private function computeLevel(int $documentId, int $userId, int $requiredLevel): bool
	{
		$document = (new DocumentRepository())->getById($documentId);
		if ($document === null)
		{
			return false;
		}

		// A document in the recycle bin answers by the rule of the recycle bin, and the ordinary access
		// level says nothing here: the module's own reading paths refuse a trashed document to everyone
		// this rule refuses, and the file behind the attachment belongs to Disk, not to the document —
		// keeping it open on a saved attachment url until the document is deleted for good would mean
		// deleting a document takes away nothing of what it lent out. Reading is the most a trashed
		// document may grant, so a question about anything above it is answered no.
		$trashRecord = (new RecycleBinRepository())->getByDocumentId($documentId);
		if ($trashRecord !== null)
		{
			return $requiredLevel <= DocumentAccessService::LEVEL_VIEW
				&& DocumentAccessService::canViewInRecycleBin($userId, $trashRecord);
		}

		return DocumentAccessService::userHasLevel(
			$documentId,
			(int)$document->getCollectionId(),
			$userId,
			$requiredLevel,
		);
	}
}
