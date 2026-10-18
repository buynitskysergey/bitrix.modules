<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Integration\Disk;

use Bitrix\Disk\Uf\Connector;
use Bitrix\Note\Internal\Access\Service\DocumentAccessService;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Repository\RecycleBinRepository;

/**
 * Tells Disk who may see a file attached to a note document.
 *
 * The verdict is about the DOCUMENT, never about the file: a reader of the document opens its
 * attachments even without any rights on the file itself. That is the whole point of attaching
 * rather than linking, and it is why Disk asks the owner entity instead of checking the file.
 *
 * Disk resolves this class from `AttachedObject::getEntityType()` — it stores the connector class
 * name, not the registry key — and instantiates it with the document id.
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
	 * Verdicts already given in this request, keyed `documentId:userId:level`.
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

	public function canRead($userId): bool
	{
		return $this->hasLevel((int)$userId, DocumentAccessService::LEVEL_VIEW);
	}

	public function canUpdate($userId): bool
	{
		return $this->hasLevel((int)$userId, DocumentAccessService::LEVEL_EDIT);
	}

	/**
	 * Test seam. Rights are rewritten between tests and document ids are reused across test classes, so
	 * a verdict remembered for the whole process would answer for the wrong document.
	 */
	public static function resetVerdicts(): void
	{
		self::$verdicts = [];
	}

	private function hasLevel(int $userId, int $requiredLevel): bool
	{
		$documentId = (int)$this->entityId;
		if ($documentId <= 0)
		{
			return false;
		}

		$key = $documentId . ':' . $userId . ':' . $requiredLevel;
		self::$verdicts[$key] ??= $this->computeLevel($documentId, $userId, $requiredLevel);

		return self::$verdicts[$key];
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
