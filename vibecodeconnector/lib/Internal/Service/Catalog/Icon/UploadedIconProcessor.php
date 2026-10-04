<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\Icon;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Vibecodeconnector\Internal\Integration\Main\IconStorageService;

final class UploadedIconProcessor
{
	public const ERROR_UPLOAD = 'ICON_UPLOAD_FAILED';
	public const ERROR_SIZE = 'ICON_TOO_LARGE';
	public const ERROR_FORMAT = 'ICON_UNSUPPORTED_FORMAT';
	public const ERROR_PIXELS = 'ICON_TOO_MANY_PIXELS';
	public const ERROR_SAVE = 'ICON_SAVE_FAILED';

	private const MAX_PIXELS = 4_000_000;

	public function __construct(
		private readonly IconStorageService $iconStorage = new IconStorageService(),
	) {}

	/**
	 * @param array<string, mixed> $file uploaded file array
	 * @return Result data on success: ['fileId' => int, 'content' => string, 'format' => string];
	 *         on failure a single Error with one of the ERROR_* codes
	 */
	public function process(array $file): Result
	{
		$result = new Result();

		$uploadError = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
		if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE)
		{
			return $result->addError(new Error('Uploaded icon exceeds the upload limit', self::ERROR_SIZE));
		}

		if ($uploadError !== UPLOAD_ERR_OK)
		{
			return $result->addError(new Error('Icon upload failed', self::ERROR_UPLOAD));
		}

		$path = (string)($file['tmp_name'] ?? '');
		if ($path === '' || !is_file($path))
		{
			return $result->addError(new Error('Uploaded icon is missing', self::ERROR_UPLOAD));
		}

		$size = (int)filesize($path);
		if ($size > IconStorageService::MAX_BYTES)
		{
			return $result->addError(new Error('Uploaded icon is larger than the limit', self::ERROR_SIZE));
		}

		$info = $this->iconStorage->getSupportedImageInfo($path);
		if ($info === null)
		{
			return $result->addError(new Error('Uploaded icon format is not supported', self::ERROR_FORMAT));
		}

		if ($info->getWidth() * $info->getHeight() > self::MAX_PIXELS)
		{
			return $result->addError(new Error('Uploaded icon has too many pixels', self::ERROR_PIXELS));
		}

		$fileArray = [
			'type' => $info->getMime(),
			'tmp_name' => $path,
			'size' => $size,
		];

		$stored = $this->iconStorage->saveImageFileWithContent($fileArray);
		if ($stored === null)
		{
			return $result->addError(new Error('Failed to store the uploaded icon', self::ERROR_SAVE));
		}

		return $result->setData($stored);
	}
}
