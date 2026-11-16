<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\File;

use Bitrix\Main\Engine\Response\BFile;
use Bitrix\Main\Engine\Response\ResizedImage;

/**
 * One reduced copy of a large picture, built lazily into resize_cache the way im does it: no row in
 * b_file, removed together with the original by CFile::Delete.
 */
final class ImagePreviewService
{
	public const MAX_SIDE = 2000;

	// Above this side the resize may exhaust the memory and leave the original in resize_cache as the
	// copy, so the original is served as is - the threshold Disk treats such a picture as a file by.
	public const MAX_SOURCE_SIDE = 7000;

	// A file of the module never changes under its id, so the browser may keep it for a day and
	// revalidate by ETag afterwards.
	public const CACHE_TIME = 86400;

	// A copy keeps only the first frame.
	private const ANIMATED_EXTENSIONS = ['gif', 'webp'];

	/**
	 * Decided by the row of b_file as it is, EXIF is not read: the largest side does not depend on
	 * the orientation anyway.
	 *
	 * @param array<string, mixed> $fileData
	 */
	public function needsPreview(array $fileData): bool
	{
		$name = (string)($fileData['ORIGINAL_NAME'] ?? '');
		if ($name === '')
		{
			$name = (string)($fileData['FILE_NAME'] ?? '');
		}

		if (!\CFile::IsImage($name, (string)($fileData['CONTENT_TYPE'] ?? '')))
		{
			return false;
		}

		if (in_array(mb_strtolower(GetFileExtension($name)), self::ANIMATED_EXTENSIONS, true))
		{
			return false;
		}

		$side = max((int)($fileData['WIDTH'] ?? 0), (int)($fileData['HEIGHT'] ?? 0));

		return $side > self::MAX_SIDE && $side <= self::MAX_SOURCE_SIDE;
	}

	/**
	 * @param array<string, mixed> $fileData
	 */
	public function createResponse(array $fileData, bool $inline): BFile
	{
		$response = $this->needsPreview($fileData)
			? ResizedImage::createByImageData($fileData, self::MAX_SIDE, self::MAX_SIDE)
				->setResizeType(BX_RESIZE_IMAGE_PROPORTIONAL)
			: BFile::createByFileData($fileData)
		;

		$response->showInline($inline);
		$response->setCacheTime(self::CACHE_TIME);

		return $response;
	}
}
