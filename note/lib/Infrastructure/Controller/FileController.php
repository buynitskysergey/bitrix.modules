<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Controller;

use Bitrix\Main\Engine\ActionFilter\Authentication;
use Bitrix\Main\Engine\ActionFilter\CloseSession;
use Bitrix\Main\Engine\ActionFilter\Csrf;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\Contract\Controllerable;
use Bitrix\Main\Engine\Response\BFile;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Note\Internal\Access\Service\DocumentAccessService;
use Bitrix\Note\Internal\Repository\DocumentFileLinkRepository;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Service\DocumentFileCleanupService;
use Bitrix\Note\Internal\Service\DocumentFileService;
use Bitrix\Note\Internal\Service\File\FileReachabilityService;
use Bitrix\Note\Internal\Util\IdNormalizer;
use Bitrix\Note\Internal\Service\NoteFileUrlService;

class FileController extends Controller
{
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

	public function showAction(int $fileId): ?BFile
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

		$documentFileService = new DocumentFileService();
		if ($documentFileService->getValidatedNoteFile($fileId) === null)
		{
			$this->addError(new Error(Loc::getMessage('NOTE_FILE_CONTROLLER_DOWNLOAD_ERROR_FILE_SOURCE')));

			return null;
		}

		return BFile::createByFileId($fileId);
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

			if ($documentFileService->getValidatedNoteFile($fileId) === null)
			{
				$failed[] = [
					'fileId' => $fileId,
					'reason' => 'file_source',
					'message' => Loc::getMessage('NOTE_FILE_CONTROLLER_DOWNLOAD_ERROR_FILE_SOURCE'),
				];

				continue;
			}

			$payload = $documentFileService->buildFilePayload($fileId);
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
			// [P4.T4, invariant N2] Only fileIds unreachable by any surviving version may be
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
		if (DocumentAccessService::currentUserHasLevel($documentId, $collectionId, DocumentAccessService::LEVEL_VIEW))
		{
			return true;
		}

		$this->addError(new Error(Loc::getMessage('NOTE_FILE_CONTROLLER_DOWNLOAD_ERROR_ACCESS')));

		return false;
	}

}
