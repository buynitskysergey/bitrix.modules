<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\File;

use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Bitrix\Main\Web\HttpClient;
use Bitrix\Main\Web\MimeType;

final class RemoteImageImportService
{
	public const MAX_IMAGE_SIZE_BYTES = 25 * 1024 * 1024;

	private const CONNECTION_TIMEOUT_SECONDS = 5;
	private const STREAM_TIMEOUT_SECONDS = 5;
	private const TOTAL_TIMEOUT_SECONDS = 15;
	private const REDIRECT_MAX = 3;
	private const ERROR_CODE = 'REMOTE_IMAGE_IMPORT_FAILED';

	public function __construct(
		private readonly ?HttpClient $httpClient = null,
		private readonly ?int $maxFileSize = null,
		private readonly ?\Closure $tempFileFactory = null,
	)
	{
	}

	public function import(string $url): Result
	{
		$result = new Result();
		if (preg_match('#^https?://#i', $url) !== 1)
		{
			return $result->addError($this->createError());
		}

		$maxFileSize = max(1, $this->maxFileSize ?? self::MAX_IMAGE_SIZE_BYTES);
		$tempPath = $this->tempFileFactory !== null
			? ($this->tempFileFactory)()
			: \CTempFile::GetFileName('clipboard-image');
		$httpClient = ($this->httpClient ?? new RemoteImageHttpClient(self::TOTAL_TIMEOUT_SECONDS))
			->setPrivateIp(false)
			->setTimeout(self::CONNECTION_TIMEOUT_SECONDS)
			->setStreamTimeout(self::STREAM_TIMEOUT_SECONDS)
			->setRedirect(true, self::REDIRECT_MAX)
			->setBodyLengthMax($maxFileSize)
		;

		try
		{
			$downloaded = $httpClient->download($url, $tempPath);
		}
		catch (\Throwable)
		{
			$downloaded = false;
		}

		if (!$downloaded || $httpClient->getStatus() !== 200 || !is_file($tempPath))
		{
			$this->deleteTempFile($tempPath);

			return $result->addError($this->createError());
		}

		$fileSize = (int)filesize($tempPath);
		$mimeType = MimeType::normalize((string)\CFile::GetContentType($tempPath, true));
		$extension = MimeType::getExtensionByMimeType($mimeType);
		if (
			$fileSize <= 0
			|| ($maxFileSize > 0 && $fileSize > $maxFileSize)
			|| !MimeType::isImage($mimeType)
			|| $mimeType === 'image/svg+xml'
			|| $extension === null
			|| $extension === 'svg'
		)
		{
			$this->deleteTempFile($tempPath);

			return $result->addError($this->createError());
		}

		$result->setData([
			'file' => [
				'name' => "clipboard-image.{$extension}",
				'size' => $fileSize,
				'type' => $mimeType,
				'tmp_name' => $tempPath,
			],
		]);

		return $result;
	}

	private function createError(): Error
	{
		return new Error((string)Loc::getMessage('NOTE_REMOTE_IMAGE_IMPORT_FAILED'), self::ERROR_CODE);
	}

	private function deleteTempFile(string $tempPath): void
	{
		if (is_file($tempPath))
		{
			unlink($tempPath);
		}
	}
}
