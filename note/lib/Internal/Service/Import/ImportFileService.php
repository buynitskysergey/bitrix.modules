<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Import;

use Bitrix\Main\IO\File;
use Bitrix\Main\IO\Path;
use Bitrix\Note\Internal\Model\ImportMapTable;
use Bitrix\Note\Internal\Repository\DocumentFileLinkRepository;

class ImportFileService
{
	private const LANDING_FILE_SOURCE_TYPE = 'landing';

	private DocumentFileLinkRepository $fileLinkRepository;

	public function __construct(?DocumentFileLinkRepository $fileLinkRepository = null)
	{
		$this->fileLinkRepository = $fileLinkRepository ?? new DocumentFileLinkRepository();
	}

	/**
	 * Resolves one downloadAttachment() result into a note-owned file id.
	 *
	 * Internal sources (wiki) duplicate the file inside Bitrix and hand back a
	 * ready `fileId`; external sources (Outline) hand back a `tmpPath` that we
	 * persist here via CFile::SaveFile. The import steps stay source-agnostic.
	 *
	 * @param array<string, mixed> $data SourceResult::$data from downloadAttachment().
	 */
	public function persistAttachment(array $data): ?int
	{
		$preSavedFileId = $data['fileId'] ?? null;
		if ($preSavedFileId !== null)
		{
			return (int)$preSavedFileId > 0 ? (int)$preSavedFileId : null;
		}

		$tmpPath = (string)($data['tmpPath'] ?? '');
		if ($tmpPath === '')
		{
			return null;
		}

		return $this->saveAttachment(
			$tmpPath,
			(string)($data['fileName'] ?? ''),
			(string)($data['contentType'] ?? 'application/octet-stream'),
			(int)($data['size'] ?? 0),
		);
	}

	public function saveAttachment(string $tmpPath, string $fileName, string $mimeType, int $size): ?int
	{
		if ($size <= 0)
		{
			return null;
		}

		$fileName = self::ensureFileExtension($fileName, $mimeType);
		$tmpFile = new File($tmpPath);

		if (!$tmpFile->isExists() || !$tmpFile->isReadable())
		{
			self::logError("Tmp file missing before SaveFile: name={$fileName}, size={$size}, tmpPath={$tmpPath}");

			return null;
		}

		$actualTmpSize = $tmpFile->getSize();
		if ($actualTmpSize !== $size)
		{
			self::logError("Tmp file size mismatch: name={$fileName}, expected={$size}, actual={$actualTmpSize}, tmpPath={$tmpPath}");
			self::deleteTmp($tmpFile);

			return null;
		}

		$fileArray = [
			'name' => $fileName,
			'tmp_name' => $tmpPath,
			'size' => $size,
			'type' => $mimeType,
			'MODULE_ID' => 'note',
		];

		$fileId = \CFile::SaveFile($fileArray, 'note/editor');

		if (!($fileId > 0))
		{
			$tmpStillExists = $tmpFile->isExists();
			self::logError("CFile::SaveFile returned " . var_export($fileId, true)
				. ": name={$fileName}, size={$size}, type={$mimeType}"
				. ", tmpExists=" . ($tmpStillExists ? 'yes' : 'no'));
		}

		self::deleteTmp($tmpFile);

		return ($fileId > 0) ? $fileId : null;
	}

	private static function deleteTmp(File $tmpFile): void
	{
		if ($tmpFile->isExists())
		{
			$tmpFile->delete();
		}
	}

	private static function ensureFileExtension(string $fileName, string $mimeType): string
	{
		$ext = Path::getExtension($fileName);
		if ($ext !== '')
		{
			return $fileName;
		}

		$mimeToExt = [
			'image/jpeg' => 'jpg',
			'image/png' => 'png',
			'image/gif' => 'gif',
			'image/webp' => 'webp',
			'image/svg+xml' => 'svg',
			'application/pdf' => 'pdf',
			'video/mp4' => 'mp4',
			'video/webm' => 'webm',
		];

		$guessedExt = $mimeToExt[$mimeType] ?? null;
		if ($guessedExt !== null)
		{
			return $fileName . '.' . $guessedExt;
		}

		return $fileName;
	}

	private static function logError(string $message): void
	{
		\CEventLog::Add([
			'SEVERITY' => \CEventLog::SEVERITY_ERROR,
			'AUDIT_TYPE_ID' => 'IMPORT_ERROR',
			'MODULE_ID' => 'note',
			'DESCRIPTION' => $message,
		]);
	}

	public function linkFileToDocument(int $documentId, int $fileId, int $userId): void
	{
		$this->fileLinkRepository->link($documentId, $fileId, $userId);
	}

	/**
	 * Copies a landing-owned file (b_file row referenced by b_landing_file) into a fresh
	 * b_file row that the note document owns; subsequent landing-side deletes leave the
	 * note copy intact (PRD R6).
	 *
	 * Idempotency: the (sourceFileId, documentId) pair is cached in b_note_import_map
	 * using a synthetic external_id so a stepper retry will not allocate a duplicate
	 * b_file row when this method is invoked a second time inside the same import.
	 *
	 * @return int|null new b_file.ID on success, null on copy failure
	 */
	public function copyFromLanding(int $sourceFileId, int $documentId, int $userId): ?int
	{
		if ($sourceFileId <= 0 || $documentId <= 0)
		{
			return null;
		}

		$cached = $this->lookupCopiedLandingFileId($sourceFileId, $documentId);
		if ($cached !== null)
		{
			$this->linkFileToDocument($documentId, $cached, $userId);

			return $cached;
		}

		// CFile::CopyFile has no MODULE_ID/SUBDIR parameters - it duplicates the source
		// row verbatim (MODULE_ID stays 'landing', file stays under /upload/landing/...).
		// DocumentFileService::isNoteFileData would then refuse to serve the row.
		// Going through MakeFileArray + SaveFile registers the duplicate as a regular
		// note upload: MODULE_ID='note' and SUBDIR='note/editor/...' from the start.
		$newFileId = $this->copyLandingFileAsNoteOwned($sourceFileId);
		if ($newFileId === null)
		{
			return null;
		}

		$this->linkFileToDocument($documentId, $newFileId, $userId);
		$this->rememberCopiedLandingFile($sourceFileId, $documentId, $newFileId);

		return $newFileId;
	}

	/**
	 * Reads the metadata an asset token needs from a file of this portal - the file the import
	 * steps have just copied, so there is no source payload to take a name or a size from.
	 *
	 * @return array{name:string, size:int, mimeType:string}
	 */
	public function describeLocalFile(int $fileId): array
	{
		$file = \CFile::GetFileArray($fileId);

		$name = (string)($file['ORIGINAL_NAME'] ?? $file['FILE_NAME'] ?? ('file-' . $fileId));
		$mime = (string)($file['CONTENT_TYPE'] ?? '');

		// Landing's b_file rows often carry a blank or generic CONTENT_TYPE; without a
		// concrete image/*-mime the note editor classifies the asset as "file" and shows
		// the "format not supported" badge instead of rendering the image. Derive a real
		// mime from the file extension when the stored value is missing or generic.
		if ($mime === '' || $mime === 'application/octet-stream' || $mime === 'binary/octet-stream')
		{
			$guess = self::guessMimeByName($name);
			if ($guess !== null)
			{
				$mime = $guess;
			}
		}

		if ($mime === '')
		{
			$mime = 'application/octet-stream';
		}

		return [
			'name' => $name,
			'size' => (int)($file['FILE_SIZE'] ?? 0),
			'mimeType' => $mime,
		];
	}

	private static function guessMimeByName(string $fileName): ?string
	{
		$ext = mb_strtolower(Path::getExtension($fileName));
		if ($ext === '')
		{
			return null;
		}

		static $map = [
			'jpg' => 'image/jpeg',
			'jpeg' => 'image/jpeg',
			'png' => 'image/png',
			'gif' => 'image/gif',
			'webp' => 'image/webp',
			'svg' => 'image/svg+xml',
			'bmp' => 'image/bmp',
			'mp4' => 'video/mp4',
			'webm' => 'video/webm',
			'mov' => 'video/quicktime',
			'pdf' => 'application/pdf',
		];

		return $map[$ext] ?? null;
	}

	private function copyLandingFileAsNoteOwned(int $sourceFileId): ?int
	{
		$fileArray = \CFile::MakeFileArray($sourceFileId);
		if (!is_array($fileArray) || empty($fileArray['tmp_name']))
		{
			self::logError("copyFromLanding: MakeFileArray({$sourceFileId}) returned " . var_export($fileArray, true));

			return null;
		}

		$fileArray['MODULE_ID'] = 'note';

		$newFileId = \CFile::SaveFile($fileArray, 'note/editor');
		if (!is_int($newFileId))
		{
			$newFileId = is_numeric($newFileId) ? (int)$newFileId : 0;
		}

		if ($newFileId <= 0)
		{
			self::logError("copyFromLanding: CFile::SaveFile({$sourceFileId}) returned " . var_export($newFileId, true));

			return null;
		}

		return $newFileId;
	}

	private function lookupCopiedLandingFileId(int $sourceFileId, int $documentId): ?int
	{
		$externalId = $this->landingFileExternalId($sourceFileId, $documentId);
		$row = ImportMapTable::getList([
			'select' => ['URL_ID'],
			'filter' => [
				'=SOURCE_TYPE' => self::LANDING_FILE_SOURCE_TYPE,
				'=EXTERNAL_ID' => $externalId,
			],
			'limit' => 1,
		])->fetch();

		if ($row === false || empty($row['URL_ID']))
		{
			return null;
		}

		$fileId = (int)$row['URL_ID'];

		return $fileId > 0 ? $fileId : null;
	}

	private function rememberCopiedLandingFile(int $sourceFileId, int $documentId, int $newFileId): void
	{
		try
		{
			ImportMapTable::add([
				'SOURCE_TYPE' => self::LANDING_FILE_SOURCE_TYPE,
				'EXTERNAL_ID' => $this->landingFileExternalId($sourceFileId, $documentId),
				'URL_ID' => (string)$newFileId,
				'DOCUMENT_ID' => $documentId,
			]);
		}
		catch (\Throwable $e)
		{
			// Unique-key violation means a parallel stepper already wrote the mapping;
			// the cached lookup on next call will find that row, so it's safe to swallow.
			self::logError(sprintf(
				'copyFromLanding: mapping save failed for sourceFileId=%d documentId=%d newFileId=%d: %s',
				$sourceFileId,
				$documentId,
				$newFileId,
				$e->getMessage(),
			));
		}
	}

	private function landingFileExternalId(int $sourceFileId, int $documentId): string
	{
		return 'file:' . $sourceFileId . ':doc:' . $documentId;
	}
}
