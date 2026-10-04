<?php

namespace Bitrix\Crm\Import\Factory;

use Bitrix\Crm\Import\Contract\File\ReaderInterface;
use Bitrix\Crm\Import\Contract\File\WriterInterface;
use Bitrix\Crm\Import\Dto\Entity\AbstractImportSettings;
use Bitrix\Crm\Import\Enum\Encoding as EncodingType;
use Bitrix\Crm\Import\Enum\File\Extension;
use Bitrix\Crm\Import\Enum\TemporaryFileType;
use Bitrix\Crm\Import\File\Reader\CSVReader;
use Bitrix\Crm\Import\File\Reader\VCardReader;
use Bitrix\Crm\Import\File\Uploader\ImportFileUploaderController;
use Bitrix\Crm\Import\File\Writer\CSVWriter;
use Bitrix\Crm\Import\File\Writer\NullWriter;
use Bitrix\Crm\Import\File\Writer\VCardWriter;
use Bitrix\Crm\Integration\UI\FileUploader;
use Bitrix\Crm\Result;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Text\Encoding;
use CFile;
use Psr\Log\LoggerInterface;

final class FileFactory
{
	private readonly LoggerInterface $logger;

	public function __construct(
		private readonly TemporaryFileFactory $temporaryFileFactory,
		private readonly ErrorFactory $errorFactory,
		?LoggerInterface $logger = null,
	)
	{
		$this->logger = $logger ?? Container::getInstance()->getLogger('Import');
	}

	public function uploadImportFile(AbstractImportSettings $importSettings): Result
	{
		$importFileId = $importSettings->getImportFileId();
		if ($importFileId === null)
		{
			$this->logger->error(
				'Import file upload failed: import file id is missing',
				[
					'userId' => (int)CurrentUser::get()->getId(),
					'importSettings' => $importSettings->toArray(),
				],
			);

			return Result::fail($this->errorFactory->getImportFileNotFoundError());
		}

		$uploadController = new ImportFileUploaderController([
			'entityTypeId' => $importSettings->getEntityTypeId(),
		]);

		$pendingFiles = (new FileUploader($uploadController))
			->getUploader()
			->getPendingFiles([
				$importFileId,
			])
		;

		$importFile = $pendingFiles->get($importFileId);
		if ($importFile === null || !$importFile->isValid())
		{
			$this->logger->error(
				'Import file upload failed: pending uploader file is missing or invalid',
				[
					'userId' => (int)CurrentUser::get()->getId(),
					'importSettings' => $importSettings->toArray(),
				],
			);

			return Result::fail($this->errorFactory->getImportFileNotFoundError());
		}

		$info = CFile::MakeFileArray($importFile->getFileId());
		if (!is_array($info) || !isset($info['tmp_name'], $info['type']))
		{
			$this->logger->error(
				'Import file upload failed: uploaded file info is incomplete',
				[
					'userId' => (int)CurrentUser::get()->getId(),
					'importSettings' => $importSettings->toArray(),
				],
			);

			return Result::fail($this->errorFactory->getImportFileNotFoundError());
		}

		$importFilePath = $info['tmp_name'];
		if (!is_readable($importFilePath))
		{
			$this->logger->error(
				'Import file upload failed: uploaded file is not readable',
				[
					'userId' => (int)CurrentUser::get()->getId(),
					'importSettings' => $importSettings->toArray(),
					'temporaryFilename' => $importFilePath,
				],
			);

			return Result::fail($this->errorFactory->getImportFileNotFoundError());
		}

		$extension = Extension::tryFromType($info['type']);
		if ($extension === null)
		{
			$this->logger->error(
				'Import file upload failed: file type is not supported',
				[
					'userId' => (int)CurrentUser::get()->getId(),
					'importSettings' => $importSettings->toArray(),
					'fileType' => $info['type'],
				],
			);

			return Result::fail($this->errorFactory->getImportFileNotSupportedError());
		}

		$newImportFilePath = $this->temporaryFileFactory->create(
			$importFileId,
			TemporaryFileType::Import,
			$extension,
		);

		$this->moveImportFileContent($importSettings, $importFilePath, $newImportFilePath);

		$this->logger->info(
			'Import file upload finished: temporary import file was created',
			[
				'userId' => (int)CurrentUser::get()->getId(),
				'importSettings' => $importSettings->toArray(),
				'temporaryFilename' => $newImportFilePath,
			],
		);

		return Result::success();
	}

	public function getImportFileReader(AbstractImportSettings $importSettings): ?ReaderInterface
	{
		$importFileId = $importSettings->getImportFileId();
		if ($importFileId === null)
		{
			$this->logger->error(
				'Import file reader creation failed: import file id is missing',
				[
					'userId' => (int)CurrentUser::get()->getId(),
					'importSettings' => $importSettings->toArray(),
				],
			);

			return null;
		}

		$filename = $this->temporaryFileFactory->get($importFileId, TemporaryFileType::Import);
		if ($filename === null)
		{
			$this->logger->error(
				'Import file reader creation failed: temporary storage key with filename is missing',
				[
					'userId' => (int)CurrentUser::get()->getId(),
					'importSettings' => $importSettings->toArray(),
				],
			);

			return null;
		}

		if (!file_exists($filename))
		{
			$this->logger->error(
				'Import file reader creation failed: temporary file is missing',
				[
					'userId' => (int)CurrentUser::get()->getId(),
					'importSettings' => $importSettings->toArray(),
					'temporaryFilename' => $filename,
				],
			);

			return null;
		}

		if (!is_readable($filename))
		{
			$this->logger->error(
				'Import file reader creation failed: temporary file is not readable',
				[
					'userId' => (int)CurrentUser::get()->getId(),
					'importSettings' => $importSettings->toArray(),
					'temporaryFilename' => $filename,
				],
			);

			return null;
		}

		$extension = Extension::tryFromFilename($filename);
		if ($extension === Extension::CSV)
		{
			return (new CSVReader($filename))
				->setDelimiter($importSettings->getDelimiter())
				->setIsFirstRowHasHeaders($importSettings->isFirstRowHasHeaders())
				->setIsSkipEmptyColumns($importSettings->isSkipEmptyColumns());
		}

		if ($extension === Extension::VCard)
		{
			return new VCardReader($filename);
		}

		$this->logger->error(
			'Import file reader creation failed: file extension is not supported',
			[
				'userId' => (int)CurrentUser::get()->getId(),
				'importSettings' => $importSettings->toArray(),
				'temporaryFilename' => $filename,
			],
		);

		return null;
	}

	public function getTemporaryFile(string $importFileId, string $rawType): ?string
	{
		$type = TemporaryFileType::tryFrom($rawType);
		if ($type === null)
		{
			$this->logger->error(
				'Import temporary file lookup failed: temporary file type is invalid',
				[
					'userId' => (int)CurrentUser::get()->getId(),
					'importFileId' => $importFileId,
					'rawType' => $rawType,
				],
			);

			return null;
		}

		$filename = $this->temporaryFileFactory->get($importFileId, $type);
		if ($filename === null)
		{
			$this->logger->error(
				'Import temporary file lookup failed: temporary storage key with filename is missing',
				[
					'userId' => (int)CurrentUser::get()->getId(),
					'importFileId' => $importFileId,
					'temporaryFileType' => $type->value,
				],
			);

			return null;
		}

		return $filename;
	}

	public function getFailImportWriter(AbstractImportSettings $importSettings): WriterInterface
	{
		return $this->getImportFileWriter($importSettings, TemporaryFileType::Error);
	}

	public function getDuplicateImportWriter(AbstractImportSettings $importSettings): WriterInterface
	{
		return $this->getImportFileWriter($importSettings, TemporaryFileType::Duplicate);
	}

	private function getImportFileWriter(AbstractImportSettings $importSettings, TemporaryFileType $type): WriterInterface
	{
		$importFileId = $importSettings->getImportFileId();
		if ($importFileId === null)
		{
			$this->logger->error(
				'Import result writer creation failed: import file id is missing',
				[
					'userId' => (int)CurrentUser::get()->getId(),
					'importSettings' => $importSettings->toArray(),
					'temporaryFileType' => $type->value,
				],
			);

			return new NullWriter();
		}

		$importFilePath = $this->temporaryFileFactory->get($importFileId, TemporaryFileType::Import);
		if ($importFilePath === null)
		{
			$this->logger->error(
				'Import result writer creation failed: temporary storage key with source filename is missing',
				[
					'userId' => (int)CurrentUser::get()->getId(),
					'importSettings' => $importSettings->toArray(),
					'temporaryFileType' => $type->value,
				],
			);

			return new NullWriter();
		}

		if (!is_writable($importFilePath))
		{
			$this->logger->error(
				'Import result writer creation failed: source temporary file is not writable',
				[
					'userId' => (int)CurrentUser::get()->getId(),
					'importSettings' => $importSettings->toArray(),
					'temporaryFileType' => $type->value,
					'temporaryFilename' => $importFilePath,
				],
			);

			return new NullWriter();
		}

		$extension = Extension::tryFromFilename($importFilePath);
		if ($extension === Extension::CSV)
		{
			$filename = $this->temporaryFileFactory->getOrCreate(
				$importSettings->getImportFileId(),
				$type,
				Extension::CSV,
			);

			$this->logger->info(
				'Import result writer created: CSV temporary file is ready',
				[
					'userId' => (int)CurrentUser::get()->getId(),
					'importSettings' => $importSettings->toArray(),
					'temporaryFileType' => $type->value,
					'temporaryFilename' => $filename,
				],
			);

			return (new CSVWriter($filename))
				->setIsFirstRowHasHeaders($importSettings->isFirstRowHasHeaders())
				->setDelimiter($importSettings->getDelimiter());
		}

		if ($extension === Extension::VCard)
		{
			$filename = $this->temporaryFileFactory->getOrCreate(
				$importSettings->getImportFileId(),
				$type,
				Extension::VCard,
			);

			$this->logger->info(
				'Import result writer created: vCard temporary file is ready',
				[
					'userId' => (int)CurrentUser::get()->getId(),
					'importSettings' => $importSettings->toArray(),
					'temporaryFileType' => $type->value,
					'temporaryFilename' => $filename,
				],
			);

			return new VCardWriter($filename);
		}

		$this->logger->error(
			'Import result writer creation failed: source temporary file extension is not supported',
			[
				'userId' => (int)CurrentUser::get()->getId(),
				'importSettings' => $importSettings->toArray(),
				'temporaryFileType' => $type->value,
				'temporaryFilename' => $importFilePath,
			],
		);

		return new NullWriter();
	}

	private function moveImportFileContent(AbstractImportSettings $importSettings, string $source, string $target): void
	{
		$encoding = $importSettings->getEncoding();

		$sourceHandle = fopen($source, 'rb');
		$targetHandle = fopen($target, 'wb');

		$this->skipBOM($sourceHandle, $encoding);

		$bufferSize = 8192;
		while (!feof($sourceHandle))
		{
			$chunk = fread($sourceHandle, $bufferSize);
			if ($chunk !== false && $chunk !== '')
			{
				$convertedChunk = Encoding::convertEncoding($chunk, $encoding->value, SITE_CHARSET);
				fwrite($targetHandle, $convertedChunk);
			}
		}

		fclose($sourceHandle);
		fclose($targetHandle);
	}

	/**
	 * @param resource $source
	 * @param EncodingType $sourceEncoding
	 * @return void
	 */
	private function skipBOM(mixed $source, EncodingType $sourceEncoding): void
	{
		$bomList = match ($sourceEncoding) {
			EncodingType::UTF8 => [
				[
					'bom' => pack('CCC', 0xEF, 0xBB, 0xBF),
					'length' => 3,
				],
			],
			EncodingType::UTF16 => [
				[
					'bom' => pack('CC', 0xFF, 0xFE),
					'length' => 2,
				],
				[
					'bom' => pack('CC', 0xFE, 0xFF),
					'length' => 2,
				],
			],
			default => [],
		};

		foreach ($bomList as $bom)
		{
			$firstBytes = fread($source, $bom['length']);
			if (strncmp($firstBytes, $bom['bom'], $bom['length']) === 0)
			{
				return;
			}

			fseek($source, 0);
		}
	}
}
