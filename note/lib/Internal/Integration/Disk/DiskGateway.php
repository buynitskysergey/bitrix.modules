<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Integration\Disk;

use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\Configuration as DiskConfiguration;
use Bitrix\Disk\Driver;
use Bitrix\Disk\File;
use Bitrix\Disk\FilePicker\SignedConfig;
use Bitrix\Disk\FilePicker\SignedConfigBuilder;
use Bitrix\Disk\Uf\FileUserType;
use Bitrix\Disk\Uf\Integration\DiskUploaderController;
use Bitrix\Disk\UrlManager;
use Bitrix\Main\Loader;
use Bitrix\Main\ObjectException;

/**
 * The module's only door to Disk files: everything about picking, attaching and describing a Disk
 * file goes through here, so a change on their side has one place to be fixed and the rest of the
 * module never learns their API. Folders are the business of {@see DiskFolderGateway} next door.
 *
 * Nothing here decides anything about access. Rights on the file are always Disk's answer — either
 * through {@see DocumentConnector} for an attached file, or through the file's own security context
 * for one that is not attached yet.
 */
final class DiskGateway
{
	/**
	 * How long a signed picker config stays usable. Disk enforces it on redemption; without one the
	 * config a browser was handed would never stop working. A pick is a matter of minutes, and the
	 * config is re-fetched on every click, so the window can be short.
	 */
	private const PICKER_CONFIG_TTL_SEC = 900;

	/**
	 * How many Disk files one document may hold attached.
	 *
	 * The set to attach is derived from the document's text, and nothing bounds how many files a text may
	 * name: an import or a REST write can carry tens of thousands of tokens, and every one of them is a
	 * row to insert and a file to check the rights on, all inside the request that saved the text. The
	 * ceiling is far above what a page of prose ever carries — a token past it is left in the text as
	 * written and resolves as unavailable, which is the honest answer for a file the document never
	 * attached.
	 */
	public const MAX_ATTACHMENTS_PER_DOCUMENT = 500;

	public function __construct(
		// A default rather than a constant used directly, so it can be lowered where creating five
		// hundred files to reach it is not an option.
		private readonly int $maxAttachmentsPerDocument = self::MAX_ATTACHMENTS_PER_DOCUMENT,
	)
	{
	}

	public function isAvailable(): bool
	{
		return Loader::includeModule('disk');
	}

	/**
	 * Disk's own switch for the universal file picker. Without the picker there is no supported way
	 * to choose a file, and building a substitute is not ours to do.
	 */
	public function isUniversalFilePickerEnabled(): bool
	{
		return $this->isAvailable() && DiskConfiguration::isUniversalFilePickerEnabled();
	}

	/**
	 * Signed picker configuration bound to the user and the current site. The scope is decided here,
	 * on the server, so the client cannot widen it.
	 *
	 * @return array<string, mixed>|null Null when Disk refused to sign the payload.
	 */
	public function buildPickerConfig(int $userId, int $maxItems): ?array
	{
		if (!$this->isAvailable())
		{
			return null;
		}

		$result = SignedConfigBuilder::create()
			->setSelectionMode(SignedConfig::SELECTION_MODE_MULTIPLE)
			->setMaxItems($maxItems)
			->setExpiresAt(time() + self::PICKER_CONFIG_TTL_SEC)
			// No file-type filter of our own: which sources and formats are offered is the picker's
			// behaviour, and narrowing it here would silently diverge from Disk.
			->bindUser($userId)
			->bindCurrentSite()
			->build()
		;

		return $result->isSuccess() ? $result->getData()['signedConfig'] : null;
	}

	/**
	 * Value Disk writes into `b_disk_attached_object.ENTITY_TYPE` for our attachments — the connector
	 * class, not the registry key {@see DocumentConnector::ENTITY_TYPE}. Asked of Disk rather than
	 * hardcoded, so a future normalisation on their side does not silently turn every stored attachment
	 * into a foreign one.
	 *
	 * Lives here and not on the connector because it needs Disk's own manager: the connector is built by
	 * Disk, per attached object, with nothing but an entity id, so it cannot be handed dependencies —
	 * which makes it the wrong place to reach for a global one.
	 */
	public function resolveStoredEntityType(): string
	{
		if (!$this->isAvailable())
		{
			return DocumentConnector::class;
		}

		[$connectorClass] = Driver::getInstance()
			->getUserFieldManager()
			->getConnectorDataByEntityType(DocumentConnector::ENTITY_TYPE)
		;

		return is_string($connectorClass) && $connectorClass !== '' ? $connectorClass : DocumentConnector::class;
	}

	/**
	 * Attachments this document holds, grouped by the Disk object behind them.
	 *
	 * @param int[] $objectIds Narrows the lookup; empty means every attachment of the document.
	 * @return array<int, int[]> objectId => attachedObjectIds
	 */
	public function listAttachments(int $documentId, array $objectIds = []): array
	{
		if ($documentId <= 0 || !$this->isAvailable())
		{
			return [];
		}

		$filter = [
			'=ENTITY_TYPE' => $this->resolveStoredEntityType(),
			'=ENTITY_ID' => $documentId,
		];
		if ($objectIds !== [])
		{
			$filter['@OBJECT_ID'] = $objectIds;
		}

		$attachments = [];
		foreach (AttachedObject::getModelList(['select' => ['*'], 'filter' => $filter]) as $attachedObject)
		{
			// A document may hold several attachments of the same file (the same token pasted twice).
			// They answer the permission question identically, so any of them will do for reading.
			$attachments[(int)$attachedObject->getObjectId()][] = (int)$attachedObject->getId();
		}

		return $attachments;
	}

	/**
	 * Everything the editor needs to draw an attachment, as Disk currently sees it.
	 *
	 * Objects are asked about in two ways, and the difference is the whole point: an attached object
	 * by its attachment id, which routes the permission question to {@see DocumentConnector}, and a
	 * not-yet-attached one by its own `n{id}` token, which routes it to the file's own rights. The
	 * second form is what makes a just-picked file resolvable before the content settles.
	 *
	 * @param int[] $objectIds
	 * @param array<int, int> $attachedIdByObjectId
	 * @return array<int, array{
	 *   name: string, size: int, mimeType: string, showUrl: string|null, downloadUrl: string|null,
	 *   inlineUrl: string|null, viewerAttrs: array|null, deleted: bool, canRestore: bool,
	 *   restoreUrl: string|null,
	 * }> Keyed by objectId; an object the user may not read is simply absent.
	 */
	public function loadAttributes(array $objectIds, array $attachedIdByObjectId): array
	{
		if ($objectIds === [] || !$this->isAvailable())
		{
			return [];
		}

		$requestIds = [];
		$objectIdByRequestId = [];
		foreach ($objectIds as $objectId)
		{
			$requestId = $attachedIdByObjectId[$objectId] ?? (FileUserType::NEW_FILE_PREFIX . $objectId);
			$requestIds[] = $requestId;
			$objectIdByRequestId[(string)$requestId] = $objectId;
		}

		$infoByObjectId = [];
		foreach (DiskUploaderController::getFileInfo($requestIds) as $info)
		{
			$objectId = $objectIdByRequestId[(string)($info['serverFileId'] ?? '')] ?? null;
			if ($objectId !== null)
			{
				$infoByObjectId[$objectId] = $info;
			}
		}

		// Loaded once for the whole call and reused below: the trash state needs a model per object and
		// so does the inline url of a not-yet-attached one, and loading each file twice on a path that
		// runs on every document open is a waste. Only objects Disk agreed to describe are loaded — the
		// rest the reader may not read anyway.
		$files = $this->loadFiles(array_keys($infoByObjectId));
		$trashState = $this->buildTrashState($files);

		$attributes = [];
		foreach ($infoByObjectId as $objectId => $info)
		{
			$customData = is_array($info['customData'] ?? null) ? $info['customData'] : [];
			$viewLink = (string)($customData['viewLink'] ?? '');
			$viewerAttrs = is_array($info['viewerAttrs'] ?? null) ? $info['viewerAttrs'] : [];

			$attributes[$objectId] = [
				'name' => (string)($info['name'] ?? ''),
				'size' => (int)($info['size'] ?? 0),
				'mimeType' => (string)($info['type'] ?? ''),
				// Where the block POINTS: Disk's own page for the file, with its own permission check.
				'showUrl' => $viewLink !== '' ? $viewLink : null,
				'downloadUrl' => isset($info['downloadUrl']) ? (string)$info['downloadUrl'] : null,
				// Where an <img>/<video> may READ from. Neither of the two above will do: the view link
				// is an HTML page, and the download url is served as an attachment, so a picture would
				// become a download prompt. Disk's own preview url is exactly this and is sized for
				// images; for everything else the inline show url is built below.
				'inlineUrl' => $this->resolveInlineUrl(
					$info,
					$files[$objectId] ?? null,
					$attachedIdByObjectId[$objectId] ?? null,
				),
				'viewerAttrs' => $viewerAttrs === [] ? null : $viewerAttrs,
				...($trashState[$objectId] ?? ['deleted' => false, 'canRestore' => false, 'restoreUrl' => null]),
			];
		}

		return $attributes;
	}

	/**
	 * Attaches the given Disk objects to the document on behalf of an actor whose rights Disk checks.
	 * An object the actor cannot read is skipped rather than reported: the text stays as written, and
	 * the attachment simply resolves as unavailable.
	 *
	 * Idempotent: an object already attached to this document is left alone. The check belongs here and
	 * not in the callers because Disk offers none — `AttachedObject::add()` neither looks for an
	 * existing row nor has a unique key to stop one, and a second row would queue the same video for
	 * transcoding again. Two callers attach (the picker, right after a file is chosen, and the
	 * projection sync), and both are safe on their own this way.
	 *
	 * The document's ceiling ({@see MAX_ATTACHMENTS_PER_DOCUMENT}) is held here for the same reason: it
	 * is the one place both callers pass through.
	 *
	 * @param int[] $objectIds
	 */
	public function attach(int $documentId, array $objectIds, int $actorId): void
	{
		if ($objectIds === [] || $documentId <= 0 || $actorId <= 0 || !$this->isAvailable())
		{
			return;
		}

		// Every attachment of the document, not just the ones asked about: what is already there is what
		// decides how much room is left. The row set is bounded by the ceiling itself.
		$existing = $this->listAttachments($documentId);
		$objectIds = array_values(array_diff($objectIds, array_keys($existing)));
		$room = $this->maxAttachmentsPerDocument - count($existing);
		if ($objectIds === [] || $room <= 0)
		{
			return;
		}

		$objectIds = array_slice($objectIds, 0, $room);

		foreach ($this->loadFiles($objectIds) as $file)
		{
			$storage = $file->getStorage();
			if ($storage === null)
			{
				continue;
			}

			$securityContext = $storage->getSecurityContext($actorId);
			if (!$file->canRead($securityContext))
			{
				continue;
			}

			$file->attachToEntity(
				[
					'id' => $documentId,
					'type' => DocumentConnector::ENTITY_TYPE,
				],
				[
					// Read access is what a document needs from an attachment, and it is all this grants.
					// Both write flags stay off, and `isEditable` is the one that matters more than it looks:
					// Disk reads it before it reads `createdBy` (see AttachedObject::canUpdate), so writing
					// down "the person attaching could edit this file" would keep answering yes long after
					// that stopped being true — the owner revokes their rights on Disk, and the attachment
					// still lets them write to the file, for as long as the document holds it. `allowEdit` is
					// the other half of the same question: it would hand writing to everyone with EDIT on the
					// document. Editing a Disk file happens in Disk, where the rights are asked as they are.
					'isEditable' => false,
					'allowEdit' => false,
					'createdBy' => $actorId,
				],
			);
		}
	}

	/**
	 * Drops every attachment the document holds, for a document that is going away for good: there is
	 * no text left to bring the attachments in line with, and nothing else would ever clean them — the
	 * projection sync only looks at documents that still exist.
	 */
	public function detachAll(int $documentId): void
	{
		$this->detachAllForDocuments([$documentId]);
	}

	/**
	 * The same, for a whole batch of documents and in one lookup.
	 *
	 * Hard delete works in chunks of a hundred documents, and asking per document meant a query per
	 * document — including every document that never held a Disk file. The deletions stay one by one:
	 * each row is an event on Disk's side, and Disk offers no batch that would fire them.
	 *
	 * @param int[] $documentIds
	 */
	public function detachAllForDocuments(array $documentIds): void
	{
		$documentIds = array_values(array_filter(
			array_map('intval', $documentIds),
			static fn(int $documentId): bool => $documentId > 0,
		));
		if ($documentIds === [] || !$this->isAvailable())
		{
			return;
		}

		$attachedObjectIds = [];
		$attachments = AttachedObject::getModelList([
			'select' => ['*'],
			'filter' => [
				'=ENTITY_TYPE' => $this->resolveStoredEntityType(),
				'@ENTITY_ID' => $documentIds,
			],
		]);
		foreach ($attachments as $attachedObject)
		{
			$attachedObjectIds[] = (int)$attachedObject->getId();
		}

		$this->detach($attachedObjectIds);
	}

	/**
	 * Drops the attachments only. The file itself stays on Disk — it belongs to somebody else and may
	 * well be referenced by other entities.
	 *
	 * @param int[] $attachedObjectIds
	 */
	public function detach(array $attachedObjectIds): void
	{
		if ($attachedObjectIds === [] || !$this->isAvailable())
		{
			return;
		}

		foreach ($attachedObjectIds as $attachedObjectId)
		{
			AttachedObject::getById($attachedObjectId)?->delete();
		}
	}

	/**
	 * URL that serves the file's bytes, or null when Disk has none to offer.
	 *
	 * Two sources answer with bytes, and which one applies depends on whose rights may be asked. Once
	 * the file is attached, the attachment is the right question: everyone who reads the document
	 * reads the file through it. Until then only the person who just picked the file has it open, and
	 * their own rights on the file are the ones Disk can check.
	 *
	 * @param array<string, mixed> $info
	 * @param File|null $file The object's own model, already loaded by the caller.
	 */
	private function resolveInlineUrl(array $info, ?File $file, ?int $attachedObjectId): ?string
	{
		// Only a picture may read from Disk's preview url. For a picture it serves the image bytes,
		// sized; for a video it is Disk's own player PAGE (`/media/...`, `text/html`), and a `<video src>`
		// pointed at a page shows an empty player with nothing to play. Everything that is not a picture
		// therefore reads through one of the show actions below.
		$previewUrl = (string)($info['serverPreviewUrl'] ?? '');
		if ($previewUrl !== '' && str_starts_with((string)($info['type'] ?? ''), 'image/'))
		{
			return $previewUrl;
		}

		if ($attachedObjectId !== null)
		{
			return UrlManager::getUrlToActionShowUfFile($attachedObjectId);
		}

		// The file has just been picked and the content has not settled yet, so there is no attachment
		// to read through — Disk's own show action for the file serves the bytes meanwhile, against the
		// picker's rights on the file. Without it the block would have no media source at all and the
		// video would stay a dead player until the first save.
		return $file === null ? null : Driver::getInstance()->getUrlManager()->getUrlForShowFile($file);
	}

	/**
	 * Files by id, and only files. One query for the whole set — measured at 1 query and 31 ms against
	 * 25 queries and 241 ms for the same 25 objects loaded one by one, on a path that runs on every
	 * document open.
	 *
	 * The type guarantee has to be earned, though. `File::load()` narrows the filter to files itself,
	 * but `File::loadBatchById()` is inherited and does not, so a non-file id in the batch reaches the
	 * model factory and makes it throw. That is reachable — {@see attach()} resolves ids straight out of
	 * the document text, and a hand-written token may name a folder — so a poisoned batch falls back to
	 * the per-id load, which does narrow and simply answers null for it.
	 *
	 * Keyed by object id, so a caller that needs the same model twice does not load it twice.
	 *
	 * @param int[] $objectIds
	 * @return array<int, File>
	 */
	private function loadFiles(array $objectIds): array
	{
		if ($objectIds === [])
		{
			return [];
		}

		try
		{
			$loaded = File::loadBatchById($objectIds, ['STORAGE']);
		}
		catch (ObjectException)
		{
			// Which id is not a file is not worth another query to find out: the whole set goes through the
			// narrowing load, and the offender comes back as null like any id Disk does not know.
			$loaded = [];
			foreach ($objectIds as $objectId)
			{
				$file = File::loadById($objectId, ['STORAGE']);
				if ($file !== null)
				{
					$loaded[] = $file;
				}
			}
		}

		$files = [];
		foreach ($loaded as $file)
		{
			$files[(int)$file->getId()] = $file;
		}

		return $files;
	}

	/**
	 * Trash state is not part of the uploader payload, so it is read from the file models directly.
	 * Takes the models rather than ids: the caller has them on hand.
	 *
	 * @param array<int, File> $files
	 * @return array<int, array{deleted: bool, canRestore: bool, restoreUrl: string|null}>
	 */
	private function buildTrashState(array $files): array
	{
		$state = [];
		foreach ($files as $file)
		{
			if (!$file->isDeleted())
			{
				$state[$file->getId()] = ['deleted' => false, 'canRestore' => false, 'restoreUrl' => null];

				continue;
			}

			$storage = $file->getStorage();
			$canRestore = $storage !== null && $file->canRestore($storage->getCurrentUserSecurityContext());

			$state[$file->getId()] = [
				'deleted' => true,
				'canRestore' => $canRestore,
				// Restoring happens in Disk's own trash can — we only point there.
				'restoreUrl' => $canRestore
					? UrlManager::getUrlFocusController('showObjectInTrashCanGrid', ['objectId' => $file->getId()])
					: null,
			];
		}

		return $state;
	}
}
