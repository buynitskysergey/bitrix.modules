<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Export;

use Bitrix\Main\IO\Directory;
use Bitrix\Main\IO\File as IoFile;
use Bitrix\Main\Security\Random;
use Bitrix\Note\Internal\Repository\DocumentFileLinkRepository;
use Bitrix\Note\Internal\Service\DocumentFileService;

/**
 * Builds a downloadable ZIP package (ZIP-01) for a document: the article file
 * ({title}.md) plus an attachments/ folder with note-owned files.
 */
class DocumentZipExportService
{
	private const MAX_ATTACHMENTS = 100;
	// Bounds the aggregate attachment payload: per-file size is only capped at ~25 MiB and the count at
	// MAX_ATTACHMENTS, so without this a single export could localise + pack gigabytes. Protects temp
	// disk and response time on the synchronous pack path.
	private const MAX_TOTAL_ATTACHMENTS_BYTES = 256 * 1024 * 1024;
	// EOCD (end of central directory) markers, used to locate the central directory in the packed
	// archive without reading the whole file into memory during attribute normalisation.
	private const EOCD_SIGNATURE = "\x50\x4b\x05\x06";
	private const EOCD_MIN_SIZE = 22;
	// Must stay >= 2 so prepareZip/downloadZip land in the same CTempFile hour bucket.
	private const PACKAGE_TTL_HOURS = 2;
	private const ATTACHMENTS_SUBDIR = 'attachments';
	// Caps the attachment entry base name; mirrors MAX_ENTRY_BASE_NAME in export-markdown-serializer.js.
	private const MAX_ENTRY_BASE_NAME = 100;
	// Force CZip to pack in a single pass. Its time-sliced Pack() yields (StatusContinue) once
	// microtime() - ZIP_START_TIME exceeds this budget; ZIP_START_TIME is a process-global
	// constant CZip define()s on the first Pack and never refreshes, so in a long-lived process
	// (test suite, batch request) it goes stale and every later Pack yields immediately, driving
	// the continuation branch that leaves a broken file handle (ftell() on false). Our packages
	// are tiny (<= MAX_ATTACHMENTS small files), so a very large budget keeps packing single-pass.
	private const PACK_STEP_TIME_SECONDS = 86400;

	/**
	 * @return string|null New packageId on success, null if the build was rejected
	 *     (too many attachments) or failed (write/archive error).
	 */
	public function build(int $documentId, string $title, string $content): ?string
	{
		$attachments = $this->collectValidAttachments($documentId, $content);
		if ($attachments === null)
		{
			return null;
		}

		$stagingDir = rtrim(\CTempFile::GetDirectoryName(0), '/') . '/';
		\CheckDirPath($stagingDir);

		// Transliterate the article name to ASCII: CZip stores entry names as CP866 without the ZIP
		// UTF-8 flag (main zip.php:1164-1166), so a Cyrillic name is mojibake in external extractors.
		// The package name is already transliterated for the same reason.
		$articleName = $this->translitBaseName($title, $documentId) . '.md';
		if (IoFile::putFileContents($stagingDir . $articleName, $content) === false)
		{
			return null;
		}

		$this->packAttachments($stagingDir, $documentId, $attachments);

		$packageId = Random::getString(32);
		$packageDir = rtrim(\CTempFile::GetDirectoryName(self::PACKAGE_TTL_HOURS, $packageId), '/') . '/';
		\CheckDirPath($packageDir);

		$packagePath = $packageDir . $this->buildDisplayName($title, $documentId);
		if (!$this->packArchive($stagingDir, $packagePath))
		{
			return null;
		}

		return $packageId;
	}

	/**
	 * Restores a previously built package by id. Only works within the browser session
	 * that built it, since the persistent CTempFile path is bound to a session token.
	 *
	 * @return array{path: string, displayName: string}|null
	 */
	public function resolvePackage(string $packageId): ?array
	{
		if ($packageId === '')
		{
			return null;
		}

		$packageDir = rtrim(\CTempFile::GetDirectoryName(self::PACKAGE_TTL_HOURS, $packageId), '/');
		$directory = new Directory($packageDir);
		if (!$directory->isExists())
		{
			return null;
		}

		// A package dir is unique per packageId and holds exactly one .zip, so the first match wins.
		foreach ($directory->getChildren() as $child)
		{
			if (!($child instanceof IoFile) || strtolower(substr($child->getName(), -4)) !== '.zip')
			{
				continue;
			}

			return [
				'path' => $child->getPath(),
				'displayName' => $child->getName(),
			];
		}

		return null;
	}

	/**
	 * Collects the attachments to pack: only files that are BOTH referenced in the exported content
	 * (attachments/{fileId}-...) AND linked to this document. A file removed from the current text but
	 * still reachable via history must not leak into the archive, and the MAX_ATTACHMENTS cap counts
	 * only what is actually exported (a heavily-versioned document is not blocked by orphan links).
	 *
	 * @return array<int, array{fileId: int, originalName: string}>|null Null means more than
	 *     MAX_ATTACHMENTS valid attachments are referenced — build must be rejected.
	 */
	private function collectValidAttachments(int $documentId, string $content): ?array
	{
		$referencedFileIds = $this->extractReferencedFileIds($content);
		if (empty($referencedFileIds))
		{
			return [];
		}

		// Cap the id set BEFORE the repository query: client content up to 1 MiB can reference tens of
		// thousands of unique ids, and the MAX_ATTACHMENTS check below only counts valid linked files,
		// so a flood of nonexistent ids would otherwise reach the IN() filter unbounded.
		if (count($referencedFileIds) > self::MAX_ATTACHMENTS)
		{
			return null;
		}

		$links = (new DocumentFileLinkRepository())->getByDocumentAndFileIds($documentId, $referencedFileIds);
		if (empty($links))
		{
			return [];
		}

		$fileIds = [];
		$seen = [];
		foreach ($links as $link)
		{
			$fileId = (int)($link['FILE_ID'] ?? 0);
			if ($fileId > 0 && !isset($seen[$fileId]))
			{
				$seen[$fileId] = true;
				$fileIds[] = $fileId;
			}
		}

		// One CFile::GetList for all files instead of a per-link GetFileArray.
		$filesMap = (new DocumentFileService())->getValidatedNoteFilesMap($fileIds);

		$attachments = [];
		$totalBytes = 0;
		foreach ($fileIds as $fileId)
		{
			$row = $filesMap[$fileId] ?? null;
			if ($row === null)
			{
				continue;
			}

			$totalBytes += (int)($row['FILE_SIZE'] ?? 0);
			if ($totalBytes > self::MAX_TOTAL_ATTACHMENTS_BYTES)
			{
				return null;
			}

			$attachments[] = [
				'fileId' => $fileId,
				'originalName' => (string)($row['ORIGINAL_NAME'] ?? $row['FILE_NAME'] ?? ('file' . $fileId)),
			];
		}

		if (count($attachments) > self::MAX_ATTACHMENTS)
		{
			return null;
		}

		return $attachments;
	}

	/**
	 * Extracts the file ids the exported content actually references via the attachments/{fileId}-
	 * link scheme written by export-markdown-serializer.js. The {fileId}- prefix is plain ASCII
	 * (digits + dash), unaffected by the percent-encoding applied to the name part.
	 *
	 * @return int[]
	 */
	private function extractReferencedFileIds(string $content): array
	{
		if (!preg_match_all('#attachments/(\d+)-#', $content, $matches))
		{
			return [];
		}

		return array_values(array_unique(array_map('intval', $matches[1])));
	}

	private function packAttachments(string $stagingDir, int $documentId, array $attachments): void
	{
		if (empty($attachments))
		{
			return;
		}

		$attachmentsDir = $stagingDir . self::ATTACHMENTS_SUBDIR . '/';
		\CheckDirPath($attachmentsDir);

		foreach ($attachments as $attachment)
		{
			$fileId = $attachment['fileId'];
			// MakeFileArray downloads the cloud-stored file into a local tmp path first;
			// GetFileArray()['SRC'] would be a URL on cloud installations, unusable for copy().
			$fileArray = \CFile::MakeFileArray($fileId);
			$sourcePath = (string)($fileArray['tmp_name'] ?? '');

			if ($sourcePath === '' || !IoFile::isFileExists($sourcePath))
			{
				AddMessage2Log(sprintf(
					'note export: attachment fileId=%d for documentId=%d has no physical file, skipped',
					$fileId,
					$documentId,
				), 'note');

				continue;
			}

			$entryName = $fileId . '-' . $this->sanitizeFileName($attachment['originalName'], (string)$fileId);
			$contents = IoFile::getFileContents($sourcePath);
			if ($contents === false || IoFile::putFileContents($attachmentsDir . $entryName, $contents) === false)
			{
				// Leaves a dangling attachments/... link in the .md for this file, but one bad copy
				// must not abort the whole export — log it and keep packing the rest.
				AddMessage2Log(sprintf(
					'note export: failed to copy attachment fileId=%d for documentId=%d into archive staging',
					$fileId,
					$documentId,
				), 'note');
			}
		}
	}

	private function packArchive(string $stagingDir, string $packagePath): bool
	{
		// CZip::Pack() ftell()s its archive handle without bailing out when fopen() fails: its
		// `if (!$this->_openFile())` guard misreads the returned error array as success, so an
		// unwritable target path crashes with a TypeError (ftell(false)) instead of erroring.
		// Pre-create the target via Main\IO and fail cleanly (build() returns null) if it cannot be
		// written; CZip overwrites this empty placeholder when it packs.
		if (IoFile::putFileContents($packagePath, '') === false)
		{
			return false;
		}

		$archiver = \CBXArchive::GetArchive($packagePath, 'ZIP');
		if (!($archiver instanceof \IBXArchive))
		{
			return false;
		}

		$stagingDirNoSlash = rtrim($stagingDir, '/');
		$archiver->SetOptions([
			'REMOVE_PATH' => $stagingDirNoSlash,
			'STEP_TIME' => self::PACK_STEP_TIME_SECONDS,
		]);

		$startFile = '';
		do
		{
			$result = $archiver->Pack([$stagingDirNoSlash], $startFile);
			$startFile = $archiver->GetStartFile();
		}
		while ($result === \IBXArchive::StatusContinue);

		if ($result !== \IBXArchive::StatusSuccess)
		{
			return false;
		}

		$this->normalizeEntryAttributes($packagePath);

		return true;
	}

	/**
	 * Normalises the non-standard external file attributes CZip stamps on every entry so strict
	 * extractors (notably the Bitrix mobile app's built-in one) render entries as files, not folders.
	 * See ZipEntryAttributeNormalizer for the full rationale. Best-effort: any read/structural surprise
	 * leaves the archive as CZip left it.
	 *
	 * Loads the packed archive via Main\IO, patches the same-length central-directory fields in place on
	 * the in-memory image and writes it back. Real packages are tiny (<= MAX_ATTACHMENTS small files)
	 * and the archive is bounded by MAX_TOTAL_ATTACHMENTS_BYTES, so the whole-file read is acceptable.
	 * The rewrite keeps the entry lengths intact, so the archive stays valid; only a crash inside the
	 * single putFileContents write could truncate an already-built, regenerable temp package.
	 */
	private function normalizeEntryAttributes(string $packagePath): void
	{
		if (!IoFile::isFileExists($packagePath))
		{
			return;
		}

		$data = IoFile::getFileContents($packagePath);
		if ($data === false)
		{
			return;
		}

		$size = strlen($data);
		if ($size < self::EOCD_MIN_SIZE)
		{
			return;
		}

		// CZip writes no archive comment, so the EOCD is the last 22 bytes; scan a generous tail
		// window anyway so a stray comment can't hide it.
		$tailLength = (int)min($size, 65557 + self::EOCD_MIN_SIZE);
		$tail = substr($data, $size - $tailLength);

		$eocdPos = strrpos($tail, self::EOCD_SIGNATURE);
		if ($eocdPos === false || $eocdPos + self::EOCD_MIN_SIZE > strlen($tail))
		{
			return;
		}

		$totalEntries = unpack('v', substr($tail, $eocdPos + 10, 2))[1];
		$centralDirectorySize = unpack('V', substr($tail, $eocdPos + 12, 4))[1];
		$centralDirectoryOffset = unpack('V', substr($tail, $eocdPos + 16, 4))[1];
		if (
			$centralDirectorySize <= 0
			|| $centralDirectoryOffset < 0
			|| $centralDirectoryOffset + $centralDirectorySize > $size
		)
		{
			return;
		}

		$centralDirectory = substr($data, $centralDirectoryOffset, $centralDirectorySize);
		if (strlen($centralDirectory) !== $centralDirectorySize)
		{
			return;
		}

		$patches = ZipEntryAttributeNormalizer::computePatches(
			$centralDirectory,
			$centralDirectoryOffset,
			$totalEntries,
		);
		if (empty($patches))
		{
			return;
		}

		// Same-length in-place patches at absolute file offsets. Byte assignment (not substr_replace,
		// which the coding standard flags as UTF-8-unsafe) keeps this on the raw binary image.
		foreach ($patches as $offset => $bytes)
		{
			$length = strlen($bytes);
			for ($i = 0; $i < $length; $i++)
			{
				$data[$offset + $i] = $bytes[$i];
			}
		}

		IoFile::putFileContents($packagePath, $data);
	}

	private function buildDisplayName(string $title, int $documentId): string
	{
		return $this->translitBaseName($title, $documentId) . '.zip';
	}

	/**
	 * Transliterates a document title into an ASCII, filesystem-safe base name (no extension),
	 * shared by the package (.zip) and article (.md) names.
	 *
	 * CZip stores zip entry names as CP866 and never sets the ZIP UTF-8 flag (main zip.php:1164-1166),
	 * so a Cyrillic name shows up as mojibake in external extractors — transliteration sidesteps that.
	 * max_len also caps the length so "{base}.{ext}" stays well under the filesystem's 255-byte
	 * per-component limit; without it a long title yields an over-long path that fopen() cannot open,
	 * and CZip::Pack() then crashes on ftell(false) instead of failing cleanly.
	 */
	private function translitBaseName(string $title, int $documentId): string
	{
		$translated = trim((string)\CUtil::translit($title, LANGUAGE_ID, [
			'max_len' => 100,
			'safe_chars' => '.',
			'replace_space' => '-',
		]));

		return $translated !== '' ? $translated : 'note-' . $documentId;
	}

	private function sanitizeFileName(string $name, string $fallback): string
	{
		// Mirror export-markdown-serializer.js (zipEntryName) BYTE-FOR-BYTE: strip any leading path,
		// then fold every character outside printable ASCII (0x20-0x7E) plus the forbidden punctuation
		// class to '_'. CZip writes entry names as CP866 without the ZIP UTF-8 flag, so a non-ASCII
		// name is mojibake in external extractors and characters outside CP866 become '?', breaking
		// the attachments/... link the client wrote into the .md even on Windows. After the fold the
		// result is pure ASCII, so the length cap below is byte-exact on both sides.
		$baseName = preg_replace('#^.*[\\\\/]#', '', $name);
		$sanitized = preg_replace('#[^\x20-\x7E]|[\\\\/:*?"\'<>|~\#&;]#u', '_', (string)$baseName);
		if (!is_string($sanitized) || $sanitized === '')
		{
			return $fallback;
		}

		return $this->capEntryBaseName($sanitized);
	}

	/**
	 * Caps an already-ASCII entry base name so "{fileId}-{name}" stays under the filesystem's 255-byte
	 * per-component limit, keeping a short extension. Mirrors capEntryBaseName in the client serializer;
	 * the input is pure ASCII, so strlen/substr are byte-exact and match the JS length/slice.
	 */
	private function capEntryBaseName(string $name): string
	{
		if (strlen($name) <= self::MAX_ENTRY_BASE_NAME)
		{
			return $name;
		}

		$dot = strrpos($name, '.');
		if ($dot !== false && $dot > 0 && strlen($name) - $dot <= 16)
		{
			$ext = substr($name, $dot);
			$keep = self::MAX_ENTRY_BASE_NAME - strlen($ext);

			return $keep > 0
				? substr($name, 0, $keep) . $ext
				: substr($name, 0, self::MAX_ENTRY_BASE_NAME);
		}

		return substr($name, 0, self::MAX_ENTRY_BASE_NAME);
	}
}
