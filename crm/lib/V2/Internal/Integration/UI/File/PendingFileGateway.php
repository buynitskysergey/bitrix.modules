<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\UI\File;

use Bitrix\Main\Error;
use Bitrix\Main\FileTable;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;
use Bitrix\Main\Security\Sign\Signer;
use Bitrix\UI\FileUploader\Contracts\CustomFingerprint;
use Bitrix\UI\FileUploader\PendingFile;
use Bitrix\UI\FileUploader\PendingFileCollection;
use Bitrix\UI\FileUploader\TempFileTable;
use Bitrix\UI\FileUploader\Uploader;
use Bitrix\UI\FileUploader\UploaderError;

final class PendingFileGateway
{
	private const BATCH_SIZE = 500;

	private const OPERATION_MAKE_PERSISTENT = 'make-persistent';
	private const OPERATION_REMOVE = 'remove';

	private const ERROR_UI_MODULE_UNAVAILABLE = 'UI_FILE_MODULE_UNAVAILABLE';
	private const ERROR_PENDING_FILE_INVALID = 'PENDING_FILE_INVALID';
	private const ERROR_FILE_ID_MISMATCH = 'PENDING_FILE_ID_MISMATCH';
	private const ERROR_TRANSITION_FAILED = 'PENDING_FILE_TRANSITION_FAILED';
	private const ERROR_TEMP_FILE_EXISTS = 'PENDING_TEMP_FILE_EXISTS';
	private const ERROR_PERSISTENT_FILE_MISSING = 'PERSISTENT_FILE_MISSING';
	private const ERROR_REMOVED_FILE_EXISTS = 'REMOVED_FILE_EXISTS';
	private const ERROR_POSTCONDITION_FAILED = 'PENDING_FILE_POSTCONDITION_FAILED';

	private readonly \Closure $pendingFileResolver;
	private readonly \Closure $tempFileExists;
	private readonly \Closure $fileExists;
	private readonly \Closure $moduleLoader;
	private readonly ?Uploader $uploader;
	private readonly ?\Closure $pendingFilesResolver;
	private readonly \Closure $existingTempFileGuidsLoader;
	private readonly \Closure $existingFileIdsLoader;

	/** @var array<string, true> */
	private array $confirmedOperations = [];

	public function __construct(
		?Uploader $uploader,
		?callable $pendingFileResolver = null,
		?callable $tempFileExists = null,
		?callable $fileExists = null,
		?callable $moduleLoader = null,
		?callable $pendingFilesResolver = null,
		?callable $existingTempFileGuidsLoader = null,
		?callable $existingFileIdsLoader = null,
	)
	{
		$this->uploader = $uploader;
		$this->pendingFileResolver = $pendingFileResolver !== null
			? \Closure::fromCallable($pendingFileResolver)
			: static function (string $token) use ($uploader): ?PendingFile {
				if ($uploader === null)
				{
					return null;
				}

				return $uploader->getPendingFiles([$token])->get($token);
			}
		;
		$this->tempFileExists = $tempFileExists !== null
			? \Closure::fromCallable($tempFileExists)
			: static fn(string $guid): bool => (bool)TempFileTable::query()
				->setSelect(['ID'])
				->where('GUID', $guid)
				->setLimit(1)
				->fetch()
		;
		$this->fileExists = $fileExists !== null
			? \Closure::fromCallable($fileExists)
			: static fn(int $fileId): bool => (bool)FileTable::query()
				->setSelect(['ID'])
				->where('ID', $fileId)
				->setLimit(1)
				->fetch()
		;
		$this->moduleLoader = $moduleLoader !== null
			? \Closure::fromCallable($moduleLoader)
			: static fn(): bool => Loader::includeModule('ui')
		;
		$this->pendingFilesResolver = $pendingFilesResolver !== null
			? \Closure::fromCallable($pendingFilesResolver)
			: (
				$uploader === null
					? null
					: static fn(array $tokens): PendingFileCollection => self::getPendingFilesBatch($uploader, $tokens)
				)
		;
		$this->existingTempFileGuidsLoader = $existingTempFileGuidsLoader !== null
			? \Closure::fromCallable($existingTempFileGuidsLoader)
			: static function (array $guids): array {
				if ($guids === [])
				{
					return [];
				}

				$existingGuids = [];
				foreach (array_chunk(array_values(array_unique($guids)), self::BATCH_SIZE) as $guidChunk)
				{
					$rows = TempFileTable::query()
						->setSelect(['GUID'])
						->whereIn('GUID', $guidChunk)
						->fetchAll()
					;
					$existingGuids += array_fill_keys(array_column($rows, 'GUID'), true);
				}

				return $existingGuids;
			}
		;
		$this->existingFileIdsLoader = $existingFileIdsLoader !== null
			? \Closure::fromCallable($existingFileIdsLoader)
			: static function (array $fileIds): array {
				if ($fileIds === [])
				{
					return [];
				}

				$existingFileIds = [];
				foreach (array_chunk(array_values(array_unique($fileIds)), self::BATCH_SIZE) as $fileIdChunk)
				{
					$rows = FileTable::query()
						->setSelect(['ID'])
						->whereIn('ID', $fileIdChunk)
						->fetchAll()
					;
					$existingFileIds += array_fill_keys(
						array_map('intval', array_column($rows, 'ID')),
						true,
					);
				}

				return $existingFileIds;
			}
		;
	}

	/**
	 * @param list<string> $tokens
	 */
	private static function getPendingFilesBatch(Uploader $uploader, array $tokens): PendingFileCollection
	{
		$pendingFiles = new PendingFileCollection();
		$guidsByToken = [];
		foreach ($tokens as $token)
		{
			if (!is_string($token) || $token === '')
			{
				continue;
			}

			$pendingFile = new PendingFile($token);
			$pendingFiles->add($pendingFile);
			$guid = self::getGuidFromToken($uploader, $token);
			if ($guid === null)
			{
				$pendingFile->addError(new UploaderError(UploaderError::INVALID_SIGNATURE));
				continue;
			}

			$guidsByToken[$token] = $guid;
		}

		$filesByGuid = [];
		$guids = array_values(array_unique($guidsByToken));
		foreach (array_chunk($guids, self::BATCH_SIZE) as $guidChunk)
		{
			$files = TempFileTable::query()
				->whereIn('GUID', $guidChunk)
				->where('UPLOADED', true)
				->fetchCollection()
			;
			foreach ($files as $file)
			{
				$filesByGuid[$file->getGuid()] = $file;
			}
		}

		foreach ($guidsByToken as $token => $guid)
		{
			$pendingFile = $pendingFiles->get($token);
			$file = $filesByGuid[$guid] ?? null;
			if ($file === null)
			{
				$pendingFile?->addError(new UploaderError(UploaderError::UNKNOWN_TOKEN));
				continue;
			}

			$pendingFile?->setTempFile($file);
		}

		return $pendingFiles;
	}

	private static function getGuidFromToken(Uploader $uploader, string $token): ?string
	{
		$parts = explode('.', $token, 2);
		if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '')
		{
			return null;
		}

		[$guid, $signature] = $parts;
		$controller = $uploader->getController();
		$options = $controller->getOptions();
		ksort($options);
		$fingerprint = $controller instanceof CustomFingerprint
			? $controller->getFingerprint()
			: (string)\bitrix_sessid()
		;
		$salt = md5(serialize([
			$guid,
			$controller->getName(),
			$options,
			$fingerprint,
		]));

		return (new Signer())->validate($guid, $signature, $salt) ? $guid : null;
	}

	public function makePersistentAndVerify(string $token, int $expectedFileId): Result
	{
		return $this->transitionAndVerify(
			$token,
			$expectedFileId,
			self::OPERATION_MAKE_PERSISTENT,
			static function (PendingFile $pendingFile): void {
				$pendingFile->makePersistent();
			},
		);
	}

	public function removeAndVerify(string $token, int $expectedFileId): Result
	{
		return $this->transitionAndVerify(
			$token,
			$expectedFileId,
			self::OPERATION_REMOVE,
			static function (PendingFile $pendingFile): void {
				$pendingFile->remove();
			},
		);
	}

	/**
	 * @param list<array{token: string, fileId: int}> $uploads
	 */
	public function makePersistentAndVerifyMany(array $uploads): Result
	{
		if ($uploads === [])
		{
			return new Result();
		}
		if ($this->pendingFilesResolver === null)
		{
			foreach ($uploads as $upload)
			{
				$result = $this->makePersistentAndVerify($upload['token'], $upload['fileId']);
				if (!$result->isSuccess())
				{
					return $result;
				}
			}

			return new Result();
		}

		try
		{
			if (!(($this->moduleLoader)()))
			{
				return $this->createError(self::ERROR_UI_MODULE_UNAVAILABLE, 'UI file module is unavailable.');
			}

			$tokens = array_column($uploads, 'token');
			$pendingFiles = ($this->pendingFilesResolver)($tokens);
			if (!$pendingFiles instanceof PendingFileCollection)
			{
				return $this->createError(self::ERROR_PENDING_FILE_INVALID, 'Pending file collection is invalid.');
			}
			$filesByToken = [];
			$validatedPendingFiles = new PendingFileCollection();
			foreach ($uploads as $upload)
			{
				$pendingFile = $pendingFiles->get($upload['token']);
				if (!$pendingFile instanceof PendingFile || !$pendingFile->isValid())
				{
					return $this->createError(self::ERROR_PENDING_FILE_INVALID, 'Pending file is invalid.');
				}
				$guid = $pendingFile->getGuid();
				if (
					$guid === null
					|| $guid === ''
					|| $upload['fileId'] <= 0
					|| $pendingFile->getFileId() !== $upload['fileId']
				)
				{
					return $this->createError(self::ERROR_FILE_ID_MISMATCH, 'Pending file id does not match.');
				}
				$filesByToken[$upload['token']] = $pendingFile;
				$validatedPendingFiles->add($pendingFile);
			}

			$validatedPendingFiles->makePersistent();
		}
		catch (\Throwable)
		{
			return $this->createError(self::ERROR_TRANSITION_FAILED, 'Pending file transition failed.');
		}

		$guids = [];
		$fileIds = [];
		foreach ($uploads as $upload)
		{
			$pendingFile = $filesByToken[$upload['token']];
			$guids[] = $pendingFile->getGuid();
			$fileIds[] = $upload['fileId'];
		}

		try
		{
			$existingTempFileGuids = ($this->existingTempFileGuidsLoader)($guids);
			$existingFileIds = ($this->existingFileIdsLoader)($fileIds);
		}
		catch (\Throwable)
		{
			return $this->createError(
				self::ERROR_POSTCONDITION_FAILED,
				'Pending file postcondition check failed.',
			);
		}

		foreach ($uploads as $upload)
		{
			$pendingFile = $filesByToken[$upload['token']];
			if (isset($existingTempFileGuids[$pendingFile->getGuid()]))
			{
				return $this->createError(self::ERROR_TEMP_FILE_EXISTS, 'Pending file still exists.');
			}
			if (!isset($existingFileIds[$upload['fileId']]))
			{
				return $this->createError(self::ERROR_PERSISTENT_FILE_MISSING, 'Persistent file does not exist.');
			}
			$this->confirmedOperations[$this->getConfirmationKey(
				$upload['token'],
				$upload['fileId'],
				self::OPERATION_MAKE_PERSISTENT,
			)] = true;
		}

		return new Result();
	}

	private function transitionAndVerify(
		string $token,
		int $expectedFileId,
		string $operation,
		\Closure $transition,
	): Result
	{
		$confirmationKey = $this->getConfirmationKey($token, $expectedFileId, $operation);
		if (isset($this->confirmedOperations[$confirmationKey]))
		{
			return new Result();
		}

		try
		{
			if (!(($this->moduleLoader)()))
			{
				return $this->createError(
					self::ERROR_UI_MODULE_UNAVAILABLE,
					'UI file module is unavailable.',
				);
			}

			$pendingFile = ($this->pendingFileResolver)($token);
			if (!$pendingFile instanceof PendingFile || !$pendingFile->isValid())
			{
				return $this->createError(
					self::ERROR_PENDING_FILE_INVALID,
					'Pending file is invalid.',
				);
			}

			$guid = $pendingFile->getGuid();
			$fileId = $pendingFile->getFileId();
			if ($guid === null || $guid === '' || $fileId === null || $fileId <= 0)
			{
				return $this->createError(
					self::ERROR_PENDING_FILE_INVALID,
					'Pending file metadata is invalid.',
				);
			}

			if ($expectedFileId <= 0 || $fileId !== $expectedFileId)
			{
				return $this->createError(
					self::ERROR_FILE_ID_MISMATCH,
					'Pending file id does not match.',
				);
			}

			$transition($pendingFile);
		}
		catch (\Throwable)
		{
			return $this->createError(
				self::ERROR_TRANSITION_FAILED,
				'Pending file transition failed.',
			);
		}

		try
		{
			if (($this->tempFileExists)($guid))
			{
				return $this->createError(
					self::ERROR_TEMP_FILE_EXISTS,
					'Pending file still exists.',
				);
			}

			$fileExists = ($this->fileExists)($fileId);
			if ($operation === self::OPERATION_MAKE_PERSISTENT && !$fileExists)
			{
				return $this->createError(
					self::ERROR_PERSISTENT_FILE_MISSING,
					'Persistent file does not exist.',
				);
			}

			if ($operation === self::OPERATION_REMOVE && $fileExists)
			{
				return $this->createError(
					self::ERROR_REMOVED_FILE_EXISTS,
					'Removed file still exists.',
				);
			}
		}
		catch (\Throwable)
		{
			return $this->createError(
				self::ERROR_POSTCONDITION_FAILED,
				'Pending file postcondition check failed.',
			);
		}

		$this->confirmedOperations[$confirmationKey] = true;

		return new Result();
	}

	private function getConfirmationKey(string $token, int $fileId, string $operation): string
	{
		return hash('sha256', $operation . "\0" . $token . "\0" . $fileId);
	}

	private function createError(string $code, string $message): Result
	{
		return (new Result())->addError(new Error($message, $code));
	}
}
