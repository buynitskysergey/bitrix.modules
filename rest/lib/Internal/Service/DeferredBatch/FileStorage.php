<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\DeferredBatch;

use Bitrix\Main\SystemException;
use Bitrix\Main\Web\Json;

/**
 * Stores deferred batch execution results via the Bitrix file storage (b_file / CFile).
 *
 * Each result is saved as a gzip-compressed JSON payload and registered in the
 * central `b_file` table.  The returned integer file ID is what callers must
 * persist in the database — it uniquely identifies the file and can be used for
 * future reads, URL generation and deletion.
 */
class FileStorage
{
	private const MODULE_ID = 'rest';
	private const SAVE_PATH = 'rest/deferred';

	/**
	 * Serialize $data to JSON, compress with gzip and register the file via CFile.
	 *
	 * @param int   $commandId Used only to derive a human-readable file name.
	 * @param array $data      Payload to persist.
	 *
	 * @return int  The `b_file.ID` of the saved file.
	 * @throws SystemException on encoding / compression / save failure.
	 */
	public function write(int $commandId, array $data): int
	{
		$json = Json::encode($data);
		if ($json === false)
		{
			throw new SystemException('Failed to encode result to JSON: ' . json_last_error_msg());
		}

		if (extension_loaded('zlib') || function_exists('gzencode'))
		{
			$fileContent = gzencode($json, 6);
			$fileName = $commandId . '.json.gz';
			$contentType = 'application/gzip';
		}
		else
		{
			$fileContent = $json;
			$fileName = $commandId . '.json';
			$contentType = 'application/json';
		}

		if ($fileContent === false)
		{
			throw new SystemException('Failed to gzip-compress result data');
		}

		$fileId = \CFile::SaveFile(
			[
				'name' => $fileName,
				'type' => $contentType,
				'content' => $fileContent,
				'MODULE_ID' => self::MODULE_ID,
			],
			self::SAVE_PATH,
		);

		if (!$fileId)
		{
			throw new SystemException('CFile::SaveFile failed for deferred batch command #' . $commandId);
		}

		return (int)$fileId;
	}

	/**
	 * Delete the stored file from both the filesystem and `b_file`.
	 *
	 * @param int $fileId The value returned by {@see write()}.
	 * @return bool true on success, false when the file record was not found.
	 */
	public function delete(int $fileId): bool
	{
		if ($fileId <= 0 || \CFile::GetByID($fileId)->Fetch() === false)
		{
			return false;
		}

		\CFile::Delete($fileId);

		return \CFile::GetByID($fileId)->Fetch() === false;
	}

}
