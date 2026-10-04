<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AI\Agent\Generator;

/**
 * Captures the on-disk state of generator output files before generation so it
 * can be restored (rolled back) when a later step fails.
 *
 * A file that did not exist at capture time is removed on restore; an existing
 * file is rewritten with its original bytes. An existing file that cannot be
 * read is neither: the capture is refused, because a snapshot holding it as
 * absent would have the rollback delete the very file it protects.
 */
final class GeneratedFilesSnapshot
{
	/**
	 * @param array<string, string|null> $entries path => original contents, or null when the file was absent
	 */
	private function __construct(
		private readonly array $entries,
	)
	{
	}

	/**
	 * @param list<string> $paths
	 *
	 * @throws \RuntimeException when an existing file cannot be read: half a snapshot protects nothing
	 */
	public static function capture(array $paths): self
	{
		$entries = [];
		foreach ($paths as $path)
		{
			if (!is_file($path))
			{
				$entries[$path] = null;

				continue;
			}

			// Suppressed like in restoreEntry(): the result is checked, and the failure is reported with
			// the path and what it means for the caller instead of as a warning in the middle of its output.
			$contents = @file_get_contents($path);
			if ($contents === false)
			{
				throw new \RuntimeException(
					"Failed to read {$path}, so no rollback snapshot of the generated files could be taken.",
				);
			}

			$entries[$path] = $contents;
		}

		return new self($entries);
	}

	/**
	 * Every entry is attempted before the outcome is reported: one file that cannot be put back must not
	 * leave the others as generation wrote them.
	 *
	 * @throws \RuntimeException naming the files the rollback could not put back
	 */
	public function restore(): void
	{
		$failed = [];
		foreach ($this->entries as $path => $contents)
		{
			if (!$this->restoreEntry($path, $contents))
			{
				$failed[] = $path;
			}
		}

		if ($failed !== [])
		{
			throw new \RuntimeException(
				'The rollback is incomplete, these files still hold what generation wrote: '
					. implode(', ', $failed),
			);
		}
	}

	/**
	 * Warnings are suppressed on purpose: the result of every operation is checked here and reported by
	 * {@see self::restore()} for all entries at once, which a warning about the first one would not do.
	 *
	 * @return bool false when the file was left as generation wrote it
	 */
	private function restoreEntry(string $path, ?string $contents): bool
	{
		if ($contents === null)
		{
			return !is_file($path) || @unlink($path);
		}

		return @file_put_contents($path, $contents) === strlen($contents);
	}
}
