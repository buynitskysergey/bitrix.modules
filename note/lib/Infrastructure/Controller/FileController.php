<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Controller;

use Bitrix\Main\Engine\ActionFilter\Authentication;
use Bitrix\Main\Engine\ActionFilter\CloseSession;
use Bitrix\Main\Engine\ActionFilter\Csrf;
use Bitrix\Main\Engine\ActionFilter\HttpMethod;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Engine\Response\BFile;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Note\Internal\Access\Service\DocumentAccessService;
use Bitrix\Note\Internal\Configuration;
use Bitrix\Note\Internal\Integration\Disk\DiskGateway;
use Bitrix\Note\Internal\Service\File\DiskAttachmentResolver;
use Bitrix\Note\Internal\Repository\DocumentFileLinkRepository;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Service\DocumentFileCleanupService;
use Bitrix\Note\Internal\Service\DocumentFileService;
use Bitrix\Note\Internal\Service\File\FileReachabilityService;
use Bitrix\Note\Internal\Service\File\ImagePreviewService;
use Bitrix\Note\Internal\Service\File\RemoteImageImportService;
use Bitrix\Note\Internal\Util\IdNormalizer;
use Bitrix\Note\Internal\Service\NoteFileUrlService;

class FileController extends Controller
{
	// The public circuit serves with the same time.
	public const SHOW_CACHE_TIME = ImagePreviewService::CACHE_TIME;

	// Matches Disk's own ceiling for a signed picker config; a document has no reason to want more
	// in a single pick.
	private const DISK_PICKER_MAX_ITEMS = 100;

	// Ceiling on one resolve request. Describing a Disk object is not cheap — storage, locks, preview
	// — so the client's batch is bounded here rather than trusted. The editor splits a document into
	// chunks well below this on its own, so the ceiling is a guard against a client that does not:
	// anything past it is dropped from the answer, and a caller wanting the rest asks for the rest.
	private const DISK_RESOLVE_MAX_ITEMS = 200;

	protected function getDefaultPreFilters(): array
	{
		return array_merge(
			parent::getDefaultPreFilters(),
			[
				new ActionFilter\NoteAccess(),
			],
		);
	}

	public function configureActions(): array
	{
		return [
			'upload' => [
				'+prefilters' => [
					new Csrf(),
					new Authentication(),
				],
			],
			'uploadImageByUrl' => [
				'+prefilters' => [
					new Csrf(),
					new Authentication(),
					new HttpMethod([HttpMethod::METHOD_POST]),
					new CloseSession(),
					new ActionFilter\RemoteImageImportRateLimiter(),
				],
			],
			'show' => [
				'-prefilters' => [
					Csrf::class,
				],
				'+prefilters' => [
					new Authentication(),
					new CloseSession(),
				],
			],
			'resolveDownload' => [
				'+prefilters' => [
					new Csrf(),
					new Authentication(),
				],
			],
			'resolveFileUrlsBatch' => [
				'+prefilters' => [
					new Csrf(),
					new Authentication(),
				],
			],
			'unlinkBatch' => [
				'+prefilters' => [
					new Csrf(),
					new Authentication(),
				],
			],
			'finalizeSnapshot' => [
				'+prefilters' => [
					new Csrf(),
					new Authentication(),
				],
			],
			'getDiskPickerConfig' => [
				'+prefilters' => [
					new Csrf(),
					new Authentication(),
				],
			],
			'resolveDiskAttachmentsBatch' => [
				'+prefilters' => [
					new Csrf(),
					new Authentication(),
				],
			],
			'attachDiskObjects' => [
				'+prefilters' => [
					new Csrf(),
					new Authentication(),
				],
			],
		];
	}

	public function uploadAction(int $documentId, int $collectionId = 0): ?array
	{
		$document = (new DocumentRepository())->getById($documentId);
		if ($document === null)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_FILE_CONTROLLER_UPLOAD_ERROR_DOCUMENT')));

			return null;
		}

		$documentCollectionId = (int)$document->getCollectionId();
		if ($collectionId > 0 && $collectionId !== $documentCollectionId)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_FILE_CONTROLLER_UPLOAD_ERROR_DOCUMENT_COLLECTION')));

			return null;
		}

		if (!$this->assertDocumentEditAccess($documentId, $documentCollectionId, 'NOTE_FILE_CONTROLLER_UPLOAD_ERROR_ACCESS'))
		{
			return null;
		}

		$uploadedFile = $this->getRequest()->getFile('file');
		if (!is_array($uploadedFile) || empty($uploadedFile['tmp_name']))
		{
			$this->addError(new Error(Loc::getMessage('NOTE_FILE_CONTROLLER_UPLOAD_ERROR_EMPTY')));

			return null;
		}

		return $this->saveDocumentFile($documentId, $uploadedFile);
	}

	public function uploadImageByUrlAction(
		int $documentId,
		string $url,
		int $collectionId = 0,
	): ?array
	{
		$document = (new DocumentRepository())->getById($documentId);
		if ($document === null)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_FILE_CONTROLLER_UPLOAD_ERROR_DOCUMENT')));

			return null;
		}

		$documentCollectionId = (int)$document->getCollectionId();
		if ($collectionId > 0 && $collectionId !== $documentCollectionId)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_FILE_CONTROLLER_UPLOAD_ERROR_DOCUMENT_COLLECTION')));

			return null;
		}

		if (!$this->assertDocumentEditAccess(
			$documentId,
			$documentCollectionId,
			'NOTE_FILE_CONTROLLER_UPLOAD_ERROR_ACCESS',
		))
		{
			return null;
		}

		$importResult = (new RemoteImageImportService())->import($url);

		if (!$importResult->isSuccess())
		{
			$this->addErrors($importResult->getErrors());

			return null;
		}

		$uploadedFile = $importResult->getData()['file'] ?? null;
		if (!is_array($uploadedFile))
		{
			$this->addError(new Error(Loc::getMessage('NOTE_FILE_CONTROLLER_UPLOAD_ERROR_SAVE')));

			return null;
		}

		try
		{
			return $this->saveDocumentFile($documentId, $uploadedFile);
		}
		finally
		{
			$tempPath = (string)($uploadedFile['tmp_name'] ?? '');
			if ($tempPath !== '' && is_file($tempPath))
			{
				unlink($tempPath);
			}
		}
	}

	private function saveDocumentFile(int $documentId, array $uploadedFile): ?array
	{
		$fileId = (int)\CFile::SaveFile([
			'name' => (string)($uploadedFile['name'] ?? ''),
			'size' => (int)($uploadedFile['size'] ?? 0),
			'type' => (string)($uploadedFile['type'] ?? ''),
			'tmp_name' => (string)($uploadedFile['tmp_name'] ?? ''),
			'MODULE_ID' => 'note',
		], 'note/editor');

		if ($fileId <= 0)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_FILE_CONTROLLER_UPLOAD_ERROR_SAVE')));

			return null;
		}

		$documentFileService = new DocumentFileService();
		if ($documentFileService->getValidatedNoteFile($fileId) === null)
		{
			\CFile::Delete($fileId);
			$this->addError(new Error(Loc::getMessage('NOTE_FILE_CONTROLLER_DOWNLOAD_ERROR_FILE_SOURCE')));

			return null;
		}

		$linkResult = (new DocumentFileLinkRepository())->link(
			$documentId,
			$fileId,
			(int)$this->getCurrentUser()->getId(),
		);
		if (!$linkResult->isSuccess())
		{
			\CFile::Delete($fileId);
			$this->addError(new Error(Loc::getMessage('NOTE_FILE_CONTROLLER_UPLOAD_ERROR_LINK')));

			return null;
		}

		$payload = $documentFileService->buildFilePayload($fileId, [
			'name' => (string)($uploadedFile['name'] ?? ''),
			'size' => (int)($uploadedFile['size'] ?? 0),
			'type' => (string)($uploadedFile['type'] ?? ''),
		]);
		if ($payload === null)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_FILE_CONTROLLER_UPLOAD_ERROR_URL')));

			return null;
		}

		return $payload;
	}

	/**
	 * With `preview` a large picture is served as its reduced copy; for any other file the flag
	 * changes nothing.
	 */
	public function showAction(int $fileId, bool $preview = false): ?BFile
	{
		if ($fileId <= 0)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_FILE_CONTROLLER_SHOW_ERROR_PARAMS')));

			return null;
		}

		$link = (new DocumentFileLinkRepository())->getLinkByFileId($fileId);
		if (!is_array($link))
		{
			$this->addError(new Error(Loc::getMessage('NOTE_FILE_CONTROLLER_DOWNLOAD_ERROR_FILE')));

			return null;
		}

		$documentId = (int)($link['DOCUMENT_ID'] ?? 0);
		$fileId = (int)($link['FILE_ID'] ?? 0);
		if ($documentId <= 0 || $fileId <= 0)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_FILE_CONTROLLER_DOWNLOAD_ERROR_FILE')));

			return null;
		}

		$document = (new DocumentRepository())->getById($documentId);
		if ($document === null)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_FILE_CONTROLLER_DOWNLOAD_ERROR_DOCUMENT')));

			return null;
		}

		if (!$this->assertDocumentViewAccess($documentId, (int)$document->getCollectionId()))
		{
			return null;
		}

		$fileData = (new DocumentFileService())->getValidatedNoteFile($fileId);
		if ($fileData === null)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_FILE_CONTROLLER_DOWNLOAD_ERROR_FILE_SOURCE')));

			return null;
		}

		if ($preview)
		{
			return (new ImagePreviewService())->createResponse($fileData, inline: false);
		}

		return BFile::createByFileId($fileId)->setCacheTime(self::SHOW_CACHE_TIME);
	}

	public function resolveFileUrlsBatchAction(int $documentId, array $fileIds): ?array
	{
		if ($documentId <= 0 || empty($fileIds))
		{
			return ['files' => []];
		}

		$document = (new DocumentRepository())->getById($documentId);
		if ($document === null)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_FILE_CONTROLLER_DOWNLOAD_ERROR_DOCUMENT')));

			return null;
		}

		if (!$this->assertDocumentViewAccess($documentId, (int)$document->getCollectionId()))
		{
			return null;
		}

		$linkRepository = new DocumentFileLinkRepository();
		$documentFileService = new DocumentFileService();
		$result = [];
		$failed = [];
		$adoptCandidates = [];

		// Files borrowed from another document (e.g. cross-document copy/paste, or REST-written
		// markdown lazily converted to nodes) are healed in place: if the viewer may edit this
		// document, adopt them (clone + link + remap id) below; otherwise they stay placeholders.
		$canEdit = DocumentAccessService::currentUserHasLevel(
			$documentId,
			(int)$document->getCollectionId(),
			DocumentAccessService::LEVEL_EDIT,
		);

		// One query for the whole batch instead of a per-file isLinked() lookup.
		$linkedFileIds = [];
		foreach ($linkRepository->getByDocumentAndFileIds($documentId, $fileIds) as $link)
		{
			$linkedFileIds[(int)$link['FILE_ID']] = true;
		}

		// And one read of b_file for the linked ones, as in the public batch.
		$validatedFiles = $documentFileService->getValidatedNoteFilesMap(array_keys($linkedFileIds));

		foreach ($fileIds as $rawFileId)
		{
			$fileId = (int)$rawFileId;
			if ($fileId <= 0)
			{
				continue;
			}

			if (!isset($linkedFileIds[$fileId]))
			{
				if ($canEdit)
				{
					$adoptCandidates[] = $fileId;
				}
				else
				{
					$failed[] = [
						'fileId' => $fileId,
						'reason' => 'not_linked',
						'message' => Loc::getMessage('NOTE_FILE_CONTROLLER_DOWNLOAD_ERROR_FILE'),
					];
				}

				continue;
			}

			if (!isset($validatedFiles[$fileId]))
			{
				$failed[] = [
					'fileId' => $fileId,
					'reason' => 'file_source',
					'message' => Loc::getMessage('NOTE_FILE_CONTROLLER_DOWNLOAD_ERROR_FILE_SOURCE'),
				];

				continue;
			}

			$payload = $documentFileService->buildFilePayload($fileId, fileData: $validatedFiles[$fileId]);
			if ($payload === null)
			{
				$failed[] = [
					'fileId' => $fileId,
					'reason' => 'not_found',
					'message' => Loc::getMessage('NOTE_FILE_CONTROLLER_DOWNLOAD_ERROR_FILE'),
				];

				continue;
			}

			$result[] = $payload;
		}

		if (!empty($adoptCandidates))
		{
			$adopted = $documentFileService->adoptFiles(
				$documentId,
				$adoptCandidates,
				(int)$this->getCurrentUser()->getId(),
			);
			foreach ($adopted['files'] as $payload)
			{
				$result[] = $payload;
			}
			foreach ($adopted['failed'] as $failure)
			{
				$failed[] = [
					'fileId' => (int)($failure['fileId'] ?? 0),
					'reason' => (string)($failure['reason'] ?? ''),
					'message' => $this->mapAdoptFailureMessage((string)($failure['reason'] ?? '')),
				];
			}
		}

		return ['files' => $result, 'failed' => $failed];
	}

	public function resolveDownloadAction(int $documentId, int $fileId): ?array
	{
		if ($documentId <= 0 || $fileId <= 0)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_FILE_CONTROLLER_DOWNLOAD_ERROR_PARAMS')));

			return null;
		}

		$document = (new DocumentRepository())->getById($documentId);
		if ($document === null)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_FILE_CONTROLLER_DOWNLOAD_ERROR_DOCUMENT')));

			return null;
		}

		if (!$this->assertDocumentViewAccess($documentId, (int)$document->getCollectionId()))
		{
			return null;
		}

		$linkRepository = new DocumentFileLinkRepository();
		if (!$linkRepository->isLinked($documentId, $fileId))
		{
			$this->addError(new Error(Loc::getMessage('NOTE_FILE_CONTROLLER_DOWNLOAD_ERROR_FILE')));

			return null;
		}

		$documentFileService = new DocumentFileService();
		if ($documentFileService->getValidatedNoteFile($fileId) === null)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_FILE_CONTROLLER_DOWNLOAD_ERROR_FILE_SOURCE')));

			return null;
		}

		$showUrl = NoteFileUrlService::createShowUrl($fileId);
		if ($showUrl === '')
		{
			$this->addError(new Error(Loc::getMessage('NOTE_FILE_CONTROLLER_UPLOAD_ERROR_URL')));

			return null;
		}

		return [
			'downloadUrl' => $showUrl,
			'showUrl' => $showUrl,
			'fileId' => $fileId,
		];
	}

	public function unlinkBatchAction(int $documentId, array $fileIds): ?array
	{
		if ($documentId <= 0)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_FILE_CONTROLLER_UNLINK_BATCH_ERROR_PARAMS')));

			return null;
		}

		$normalizedFileIds = IdNormalizer::normalize($fileIds);
		if (empty($normalizedFileIds))
		{
			$this->addError(new Error(Loc::getMessage('NOTE_FILE_CONTROLLER_UNLINK_BATCH_ERROR_PARAMS')));

			return null;
		}

		$document = (new DocumentRepository())->getById($documentId);
		if ($document === null)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_FILE_CONTROLLER_UNLINK_BATCH_ERROR_DOCUMENT')));

			return null;
		}

		if (!$this->assertDocumentEditAccess($documentId, (int)$document->getCollectionId(), 'NOTE_FILE_CONTROLLER_UNLINK_BATCH_ERROR_ACCESS'))
		{
			return null;
		}

		$linkedFileIds = array_map(
			static fn(array $link): int => (int)$link['FILE_ID'],
			(new DocumentFileLinkRepository())->getByDocumentAndFileIds($documentId, $normalizedFileIds),
		);
		// Requested ids that aren't even linked to this document — same "failed" outcome the
		// previous cleanupByDocumentAndFileIds reported for them.
		$successFileIds = [];
		$failedFileIds = array_values(array_diff($normalizedFileIds, $linkedFileIds));

		if (!empty($linkedFileIds))
		{
			// Only fileIds unreachable by any surviving version may be
			// physically removed. A still-reachable file keeps both its link row and its CFile —
			// the client already dropped the token from the live content, which is all "unlink"
			// means for it; the link row remains the reachability floor for its versions.
			$unreachableFileIds = array_values(array_intersect(
				$linkedFileIds,
				(new FileReachabilityService())->getUnreachableFileIds($documentId),
			));
			$reachableFileIds = array_values(array_diff($linkedFileIds, $unreachableFileIds));
			$successFileIds = array_merge($successFileIds, $reachableFileIds);

			if (!empty($unreachableFileIds))
			{
				$cleanupResult = (new DocumentFileCleanupService())->cleanupUnreachableFiles($documentId, $unreachableFileIds);
				$cleanupData = $cleanupResult->getData();
				$successFileIds = array_merge($successFileIds, array_map(
					static fn($id): int => (int)$id,
					(array)($cleanupData['successFileIds'] ?? []),
				));
				$failedFileIds = array_merge($failedFileIds, array_map(
					static fn($id): int => (int)$id,
					(array)($cleanupData['failedFileIds'] ?? []),
				));

				if (!$cleanupResult->isSuccess())
				{
					$failedFileIds = array_merge($failedFileIds, $unreachableFileIds);
				}
			}
		}

		$normalizeIds = static fn(array $ids): array => array_values(array_unique(array_filter(
			$ids,
			static fn(int $id): bool => $id > 0,
		)));

		return [
			'successIds' => $normalizeIds($successFileIds),
			'failedIds' => $normalizeIds($failedFileIds),
		];
	}

	/**
	 * [API-01] Signed config the editor needs to open Disk's file picker. The selection scope is set
	 * here and signed, so the client can neither widen it nor forge one of its own.
	 */
	public function getDiskPickerConfigAction(int $documentId): ?array
	{
		if (!Configuration::isDiskAttachmentsEnabled())
		{
			$this->addError(new Error(Loc::getMessage('NOTE_FILE_CONTROLLER_DISK_ERROR_DISABLED')));

			return null;
		}

		$document = (new DocumentRepository())->getById($documentId);
		if ($document === null)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_FILE_CONTROLLER_DISK_ERROR_DOCUMENT')));

			return null;
		}

		if (!$this->assertDocumentEditAccess($documentId, (int)$document->getCollectionId(), 'NOTE_FILE_CONTROLLER_DISK_ERROR_ACCESS'))
		{
			return null;
		}

		$signedConfig = (new DiskGateway())->buildPickerConfig(
			(int)CurrentUser::get()->getId(),
			self::DISK_PICKER_MAX_ITEMS,
		);

		if ($signedConfig === null)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_FILE_CONTROLLER_DISK_ERROR_PICKER_CONFIG')));

			return null;
		}

		return ['signedConfig' => $signedConfig];
	}

	/**
	 * Attaches files just chosen in the picker to the document, before their tokens reach the stored
	 * text.
	 *
	 * The attachment is what lets everyone who reads the document read the file: without it Disk asks
	 * about the reader's own rights on somebody else's file and answers no. Waiting for the text to be
	 * stored would leave the block unavailable to readers for as long as that takes, and would settle
	 * the attachment on whoever's write happened to land first — a co-editor without rights on the file
	 * included. Both are answered by attaching here: at the one moment when the person asking is the
	 * person who chose the file, and their rights are the ones Disk should be checking.
	 *
	 * A file that ends up not being kept in the text is not a problem to solve here: the projection sync
	 * drops an attachment the stored text does not mention
	 * ({@see \Bitrix\Note\Internal\Service\File\DiskAttachmentSyncService}), so a
	 * picked-then-undone file stops being attached on the next pass.
	 *
	 * Gated by the feature flag, like the picker config it follows: this is the picking path, and a
	 * portal with the feature off must not attach anything new.
	 *
	 * [API-01] Answers with the objects the document actually holds and the kind of block each belongs
	 * in, so both clients — the web picker and the native one — build their blocks from one source. An
	 * object the reader may not read, and one the document's ceiling left no room for, is absent from
	 * `items` exactly as it is absent from the attachments, and nothing is said about it.
	 */
	public function attachDiskObjectsAction(int $documentId, array $objectIds): ?array
	{
		if (!Configuration::isDiskAttachmentsEnabled())
		{
			$this->addError(new Error(Loc::getMessage('NOTE_FILE_CONTROLLER_DISK_ERROR_DISABLED')));

			return null;
		}

		// The ids come back from the picker, whose own ceiling is DISK_PICKER_MAX_ITEMS; the slice is
		// against a forged request, not against the picker.
		$normalizedObjectIds = array_slice(IdNormalizer::normalize($objectIds), 0, self::DISK_PICKER_MAX_ITEMS);
		if ($documentId <= 0 || $normalizedObjectIds === [])
		{
			return ['attached' => false, 'items' => []];
		}

		$document = (new DocumentRepository())->getById($documentId);
		if ($document === null)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_FILE_CONTROLLER_DISK_ERROR_DOCUMENT')));

			return null;
		}

		// Editing rights, not viewing: attaching a file to a document is part of writing it.
		if (!$this->assertDocumentEditAccess($documentId, (int)$document->getCollectionId(), 'NOTE_FILE_CONTROLLER_DISK_ERROR_ACCESS'))
		{
			return null;
		}

		// Silent about the outcome per object on purpose: a file the actor cannot read is skipped by the
		// gateway, and the resolve that follows this call is what tells the editor how each block stands.
		$items = (new DiskGateway())->attach(
			$documentId,
			$normalizedObjectIds,
			(int)CurrentUser::get()->getId(),
		);

		return ['attached' => true, 'items' => $items];
	}

	/**
	 * [API-02] Attributes of the Disk attachments referenced by the document's content.
	 *
	 * Deliberately not gated by isDiskAttachmentsEnabled(): the flag governs picking a new file, and
	 * switching it off must not blank out attachments already saved in documents.
	 */
	public function resolveDiskAttachmentsBatchAction(int $documentId, array $objectIds): ?array
	{
		if ($documentId <= 0 || empty($objectIds))
		{
			return ['items' => []];
		}

		$document = (new DocumentRepository())->getById($documentId);
		if ($document === null)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_FILE_CONTROLLER_DISK_ERROR_DOCUMENT')));

			return null;
		}

		if (!$this->assertDocumentViewAccess($documentId, (int)$document->getCollectionId()))
		{
			return null;
		}

		return [
			'items' => (new DiskAttachmentResolver())->resolve(
				$documentId,
				array_slice($objectIds, 0, self::DISK_RESOLVE_MAX_ITEMS),
			),
		];
	}

	public function finalizeSnapshotAction(int $documentId, array $referencedFileIds = []): ?array
	{
		return [
			'deletedIds' => [],
			'failedIds' => [],
			'unknownReferencedIds' => [],
			'linkedIds' => [],
		];
	}

	private function mapAdoptFailureMessage(string $reason): string
	{
		$code = match ($reason)
		{
			'file_source' => 'NOTE_FILE_CONTROLLER_DOWNLOAD_ERROR_FILE_SOURCE',
			'access' => 'NOTE_FILE_CONTROLLER_DOWNLOAD_ERROR_ACCESS',
			default => 'NOTE_FILE_CONTROLLER_DOWNLOAD_ERROR_FILE',
		};

		return (string)Loc::getMessage($code);
	}

	private function assertDocumentEditAccess(int $documentId, int $collectionId, string $errorCode): bool
	{
		if (DocumentAccessService::currentUserHasLevel($documentId, $collectionId, DocumentAccessService::LEVEL_EDIT))
		{
			return true;
		}

		$this->addError(new Error(Loc::getMessage($errorCode)));

		return false;
	}

	private function assertDocumentViewAccess(int $documentId, int $collectionId): bool
	{
		if (DocumentAccessService::currentUserCanViewDocumentContent($documentId, $collectionId))
		{
			return true;
		}

		$this->addError(new Error(Loc::getMessage('NOTE_FILE_CONTROLLER_DOWNLOAD_ERROR_ACCESS')));

		return false;
	}
}
