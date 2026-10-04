<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Controller;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\UI\Viewer\ItemAttributes;
use Bitrix\Note\Internal\Access\AccessController;
use Bitrix\Note\Internal\Access\ActionDictionary;
use Bitrix\Note\Internal\Access\Service\DocumentAccessService;
use Bitrix\Note\Internal\Repository\DocumentFileLinkRepository;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Service\Analytics\AnalyticsDictionary;
use Bitrix\Note\Internal\Service\Analytics\AnalyticsService;
use Bitrix\Note\Internal\Service\DocumentFileService;
use Bitrix\Note\Internal\Service\License\LicenseService;
use Bitrix\Note\Internal\Service\NoteFileUrlService;
use Bitrix\UI\FileUploader\CommitOptions;
use Bitrix\UI\FileUploader\Configuration;
use Bitrix\UI\FileUploader\FileOwnershipCollection;
use Bitrix\UI\FileUploader\UploaderError;
use Bitrix\UI\FileUploader\UploadResult;
use Bitrix\UI\FileUploader\UploaderController;

class EditorUploaderController extends UploaderController
{
	private const MAX_FILE_SIZE = 5 * 1024 * 1024 * 1024;

	private bool $isUploadContextResolved = false;
	private ?array $uploadContext = null;
	private ?array $accessSnapshot = null;

	public function __construct(array $options)
	{
		$options['documentId'] = isset($options['documentId']) ? (int)$options['documentId'] : 0;
		$options['collectionId'] = isset($options['collectionId']) ? (int)$options['collectionId'] : 0;

		parent::__construct($options);
	}

	public function isAvailable(): bool
	{
		// Tariff/tool gate before ACL: same denial as an ACL failure.
		if ($this->createLicenseService()->isAccessBlocked())
		{
			return false;
		}

		$userId = (int)CurrentUser::get()->getId();

		return $userId > 0 && AccessController::getCurrent()->check(ActionDictionary::ACTION_NOTE_ACCESS);
	}

	protected function createLicenseService(): LicenseService
	{
		return new LicenseService();
	}

	public function getConfiguration(): Configuration
	{
		return (new Configuration())
			->setMaxFileSize(self::MAX_FILE_SIZE)
			->setTreatOversizeImageAsFile(true)
		;
	}

	public function getCommitOptions(): CommitOptions
	{
		return new CommitOptions([
			'moduleId' => 'note',
			'savePath' => 'note/editor',
		]);
	}

	public function canUpload()
	{
		$snapshot = $this->resolveAccessSnapshot();

		return $snapshot !== null && $snapshot['canEdit'];
	}

	public function canView(): bool
	{
		$snapshot = $this->resolveAccessSnapshot();

		return $snapshot !== null && $snapshot['canView'];
	}

	public function verifyFileOwner(FileOwnershipCollection $files): void
	{
		$context = $this->resolveUploadContext();
		if ($context === null)
		{
			return;
		}

		$linkRepository = new DocumentFileLinkRepository();
		$documentId = (int)$context['documentId'];
		foreach ($files as $file)
		{
			$fileId = (int)$file->getId();
			if ($fileId > 0 && $linkRepository->isLinked($documentId, $fileId))
			{
				$file->markAsOwn();
			}
		}
	}

	public function canRemove(): bool
	{
		$snapshot = $this->resolveAccessSnapshot();

		return $snapshot !== null && $snapshot['canEdit'];
	}

	public function onUploadComplete(UploadResult $uploadResult): void
	{
		$fileInfo = $uploadResult->getFileInfo();
		if ($fileInfo === null)
		{
			return;
		}

		$context = $this->resolveUploadContext();
		$fileId = $fileInfo->getFileId();
		if ($context === null || $fileId <= 0)
		{
			return;
		}

		$documentId = (int)$context['documentId'];
		$userId = (int)CurrentUser::get()->getId();
		$documentFileService = new DocumentFileService();
		if ($documentFileService->getValidatedNoteFile($fileId) === null)
		{
			$this->rollbackUpload($fileId, $documentId, false);
			$uploadResult->addError(new UploaderError('NOTE_EDITOR_UPLOAD_REJECTED', (string)Loc::getMessage('NOTE_EDITOR_UPLOADER_CONTROLLER_ERROR_FILE_VALIDATION')));

			return;
		}

		// Analytics size unit is megabytes with 2 decimals: note files are usually < 1 MB, so
		// integer MB would floor almost everything to 0 and drop the signal.
		$sizeMb = round($fileInfo->getSize() / (1024 * 1024), 2);
		$linkResult = (new DocumentFileLinkRepository())->link($documentId, $fileId, $userId);
		if (!$linkResult->isSuccess())
		{
			AnalyticsService::fileAdded($sizeMb, false, AnalyticsDictionary::TYPE_BK);
			$this->rollbackUpload($fileId, $documentId, false);
			$uploadResult->addError(new UploaderError('NOTE_EDITOR_UPLOAD_REJECTED', (string)Loc::getMessage('NOTE_EDITOR_UPLOADER_CONTROLLER_ERROR_LINK_FILE')));

			return;
		}

		AnalyticsService::fileAdded($sizeMb, true, AnalyticsDictionary::TYPE_BK);

		$showUrl = NoteFileUrlService::createShowUrl($fileId);
		if ($showUrl === '')
		{
			$this->rollbackUpload($fileId, $documentId, true);
			$uploadResult->addError(new UploaderError('NOTE_EDITOR_UPLOAD_REJECTED', (string)Loc::getMessage('NOTE_EDITOR_UPLOADER_CONTROLLER_ERROR_PROTECTED_URL')));

			return;
		}

		$viewerAttrs = ItemAttributes::tryBuildByFileId($fileId, $showUrl)
			->setTitle($fileInfo->getName())
			->toDataSet()
		;

		$fileInfo->setDownloadUrl($showUrl);
		if ($fileInfo->isImage() || $fileInfo->isVideo())
		{
			$fileInfo->setPreviewUrl(
				$showUrl,
				max(0, $fileInfo->getWidth()),
				max(0, $fileInfo->getHeight()),
			);
		}
		$fileInfo->setViewerAttrs($viewerAttrs);
		$fileInfo->setCustomData([
			'fileId' => $fileId,
			'documentId' => $documentId,
			'collectionId' => (int)$context['collectionId'],
			'showUrl' => $showUrl,
			'viewerAttrs' => $viewerAttrs,
		]);

		$uploadResult->getTempFile()?->makePersistent();
	}

	private function rollbackUpload(int $fileId, int $documentId, bool $isLinked): void
	{
		if ($isLinked)
		{
			(new DocumentFileLinkRepository())->unlink($documentId, $fileId);
		}

		if ($fileId > 0)
		{
			\CFile::Delete($fileId);
		}
	}

	private function resolveAccessSnapshot(): ?array
	{
		if ($this->accessSnapshot !== null)
		{
			return $this->accessSnapshot;
		}

		$context = $this->resolveUploadContext();
		if ($context === null)
		{
			return null;
		}

		$this->accessSnapshot = DocumentAccessService::getCurrentUserSnapshot(
			(int)$context['documentId'],
			(int)$context['collectionId'],
		);

		return $this->accessSnapshot;
	}

	private function resolveUploadContext(): ?array
	{
		if ($this->isUploadContextResolved)
		{
			return $this->uploadContext;
		}

		$this->isUploadContextResolved = true;

		$documentId = (int)$this->getOption('documentId');
		$clientCollectionId = (int)$this->getOption('collectionId');
		if ($documentId <= 0)
		{
			$this->uploadContext = null;

			return null;
		}

		$document = (new DocumentRepository())->getById($documentId);
		if ($document === null)
		{
			$this->uploadContext = null;

			return null;
		}

		$collectionId = (int)$document->getCollectionId();
		if ($collectionId <= 0)
		{
			$this->uploadContext = null;

			return null;
		}

		if ($clientCollectionId > 0 && $clientCollectionId !== $collectionId)
		{
			$this->uploadContext = null;

			return null;
		}

		$this->uploadContext = [
			'documentId' => $documentId,
			'collectionId' => $collectionId,
		];

		return $this->uploadContext;
	}

}
