<?php

declare(strict_types=1);

namespace Bitrix\Disk\QuickAccess\FileInfo;

use Bitrix\Disk\AttachedObject;
use Bitrix\Disk\File;
use Bitrix\Disk\TypeFile;
use Bitrix\Disk\Version;
use Bitrix\Main\NotImplementedException;
use Bitrix\Main\UI\Viewer\PreviewManager;
use RuntimeException;

class DiskProvider extends BaseProvider
{
	private ?int $bFileId = null;
	private File|Version $source;

	protected function __construct(mixed $file)
	{
		if (!$file instanceof File && !$file instanceof Version)
		{
			throw new RuntimeException('Unsupported quick access source');
		}

		$this->source = $file;
	}

	public static function create(mixed $file): ?static
	{
		if ($file instanceof AttachedObject)
		{
			$file = $file->isSpecificVersion() ? $file->getVersion() : $file->getFile();
		}

		if ($file instanceof Version || $file instanceof File)
		{
			return new static($file);
		}

		return null;
	}

	public function getBFileId(): int
	{
		if ($this->bFileId === null)
		{
			$fileData = $this->source->getFile();
			if ($fileData === null)
			{
				throw new RuntimeException('Failed to get source file data');
			}

			$this->bFileId = (int)$fileData['ID'];
		}

		return $this->bFileId;
	}

	public function getFileName(): string
	{
		return $this->source->getName();
	}

	/**
	 * Extract file information for quick access
	 *
	 * @return array|null File information or null if extraction failed
	 * @throws NotImplementedException
	 */
	public function getFileInfo(): ?FileInfoDto
	{
		$fileData = $this->source->getFile();
		if (
			!is_array($fileData)
			|| empty($fileData)
		)
		{
			return null;
		}

		if (!$this->isMediaFile($fileData))
		{
			return null;
		}

		$fileInfo = $this->getInfoForAccelRedirect($fileData);
		if ($fileInfo->id <= 0)
		{
			return null;
		}

		if ($this->source instanceof File && TypeFile::isVideo($this->getTypeFileSource()))
		{
			$previewFileData = $this->source->getView()->getPreviewData();
			if (is_array($previewFileData) && isset($previewFileData['ID']))
			{
				$fileInfo->preview = $this->getInfoForAccelRedirect($previewFileData);
			}
		}

		return $fileInfo;
	}

	public function getPreviewFileInfo(): ?FileInfoDto
	{
		$previewRow = (new PreviewManager())->getFilePreviewEntryByFileId($this->getBFileId());
		$previewImageId = (int)($previewRow['PREVIEW_IMAGE_ID'] ?? 0);
		if ($previewImageId <= 0)
		{
			return null;
		}

		$previewFileData = \CFile::getFileArray($previewImageId);
		if (!is_array($previewFileData))
		{
			return null;
		}

		$fileInfo = $this->getInfoForAccelRedirect($previewFileData);

		return $fileInfo->id > 0 ? $fileInfo : null;
	}

	public function getSourceId(): string
	{
		return $this->source instanceof Version
			? 'DiskVersion:' . $this->source->getId()
			: 'DiskFile:' . $this->source->getId();
	}

	/**
	 * Check if the object is an image or media file like video/audio
	 *
	 * @param array $fileData File data
	 * @return bool True if the object is an image or media file, false otherwise
	 * @throws NotImplementedException
	 */
	private function isMediaFile(array $fileData): bool
	{
		$typeFileSource = $this->getTypeFileSource();
		if (TypeFile::isVideo($typeFileSource))
		{
			return true;
		}

		if (TypeFile::isAudio($typeFileSource))
		{
			return true;
		}

		if (!TypeFile::isImage($typeFileSource))
		{
			return false;
		}

		return \CFile::IsImage($this->getFileName(), $fileData['CONTENT_TYPE']);
	}

	private function getTypeFileSource(): File|string
	{
		return $this->source instanceof File ? $this->source : $this->source->getName();
	}

	/**
	 * Get information for X-Accel-Redirect
	 *
	 * @param array $fileData File data
	 * @return FileInfoDto Information for redirect or null if failed
	 */
	private function getInfoForAccelRedirect(array $fileData): FileInfoDto
	{
		return self::createFileInfo($fileData);
	}
}
