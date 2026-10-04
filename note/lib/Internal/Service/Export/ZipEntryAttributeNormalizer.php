<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Export;

/**
 * Rewrites the central-directory external file attributes CZip stamps on every entry.
 *
 * CZip writes 0xFE49FFE0 for files (main zip.php:1160): a DOS attribute byte 0xE0 with the bogus
 * 0x40/0x80 bits set and a Unix mode whose type nibble is 0xF000 — not S_IFREG. Lenient extractors
 * (WinRAR, desktop unzip) fall back to the name and treat the entry as a file, but the Bitrix mobile
 * app's built-in extractor reads the Unix mode, sees "not a regular file" and renders every entry as a
 * folder, so the downloaded archive looks empty. CZip offers no option or callback to change the field
 * (callback_pre_add only writes back stored_filename) and the core class must not be patched, so the
 * value is normalised in place after packing.
 *
 * Pure bytes-in/bytes-out so it can be unit-tested without touching the filesystem. Best-effort: any
 * structural surprise (no end-of-central-directory record, a header signature mismatch) returns the
 * input unchanged rather than risk corrupting a valid archive.
 */
final class ZipEntryAttributeNormalizer
{
	private const EOCD_SIGNATURE = "\x50\x4b\x05\x06";
	private const CDFH_SIGNATURE = "\x50\x4b\x01\x02";
	private const EOCD_MIN_SIZE = 22;
	private const CDFH_FIXED_SIZE = 46;

	// Unix st_mode in the high 16 bits, where zip stores it; DOS directory bit 0x10 kept only for
	// directory entries so DOS-only readers still classify them correctly.
	private const FILE_EXTERNAL_ATTRS = 0100644 << 16;
	private const DIR_EXTERNAL_ATTRS = (040755 << 16) | 0x10;
	// "version made by": host = Unix (3) so the Unix mode above is authoritative, spec version 2.0.
	private const VERSION_MADE_BY = (3 << 8) | 20;

	public static function normalize(string $zipBytes): string
	{
		if (strlen($zipBytes) < self::EOCD_MIN_SIZE)
		{
			return $zipBytes;
		}

		$eocdPos = strrpos($zipBytes, self::EOCD_SIGNATURE);
		if ($eocdPos === false)
		{
			return $zipBytes;
		}

		// A false EOCD-signature match within the trailing 21 bytes (e.g. inside an archive comment)
		// would leave fewer than the fixed 22 bytes to read the entry count/offset from — bail out
		// so the promise "any structural surprise returns the input unchanged" holds without relying
		// on unpack() coercing a short buffer.
		if ($eocdPos + self::EOCD_MIN_SIZE > strlen($zipBytes))
		{
			return $zipBytes;
		}

		$totalEntries = unpack('v', substr($zipBytes, $eocdPos + 10, 2))[1];
		$centralDirectoryOffset = unpack('V', substr($zipBytes, $eocdPos + 16, 4))[1];
		if ($centralDirectoryOffset < 0 || $centralDirectoryOffset > $eocdPos)
		{
			return $zipBytes;
		}

		$patches = self::computePatches(
			substr($zipBytes, $centralDirectoryOffset, $eocdPos - $centralDirectoryOffset),
			$centralDirectoryOffset,
			$totalEntries,
		);
		if ($patches === null)
		{
			return $zipBytes;
		}

		// Same-length in-place writes: assigning bytes directly avoids reallocating the whole archive
		// string on every field, so the buffer is copied at most once via copy-on-write.
		foreach ($patches as $offset => $bytes)
		{
			$length = strlen($bytes);
			for ($i = 0; $i < $length; $i++)
			{
				$zipBytes[$offset + $i] = $bytes[$i];
			}
		}

		return $zipBytes;
	}

	/**
	 * Pure core of the rewrite: given the central-directory bytes and their absolute offset in the
	 * archive, returns the same-length field patches (absolute offset => bytes) that make each entry
	 * read as a regular file/directory. Returns null on any structural surprise so the caller leaves
	 * the archive exactly as CZip wrote it. Filesystem-free, so it drives both the in-memory string
	 * path (normalize) and the streaming fseek/fwrite path in DocumentZipExportService that never
	 * loads the whole archive into memory.
	 *
	 * @return array<int, string>|null
	 */
	public static function computePatches(
		string $centralDirectory,
		int $centralDirectoryOffset,
		int $totalEntries,
	): ?array
	{
		$patches = [];
		$pos = 0;
		$length = strlen($centralDirectory);

		for ($i = 0; $i < $totalEntries; $i++)
		{
			// The fixed header must fit before we read any field from it (closes the case of a buffer
			// truncated mid-header after a valid signature).
			if ($pos + self::CDFH_FIXED_SIZE > $length)
			{
				return null;
			}
			if (substr($centralDirectory, $pos, 4) !== self::CDFH_SIGNATURE)
			{
				return null;
			}

			$nameLen = unpack('v', substr($centralDirectory, $pos + 28, 2))[1];
			$extraLen = unpack('v', substr($centralDirectory, $pos + 30, 2))[1];
			$commentLen = unpack('v', substr($centralDirectory, $pos + 32, 2))[1];
			$name = substr($centralDirectory, $pos + self::CDFH_FIXED_SIZE, $nameLen);
			$external = str_ends_with($name, '/') ? self::DIR_EXTERNAL_ATTRS : self::FILE_EXTERNAL_ATTRS;

			$patches[$centralDirectoryOffset + $pos + 4] = pack('v', self::VERSION_MADE_BY);
			$patches[$centralDirectoryOffset + $pos + 38] = pack('V', $external);

			$pos += self::CDFH_FIXED_SIZE + $nameLen + $extraLen + $commentLen;
		}

		return $patches;
	}
}
