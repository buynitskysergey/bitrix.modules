<?php

namespace Bitrix\MessageService\Internal\Service\CustomTemplate;

use Bitrix\Main\DB\DuplicateEntryException;

final class NameConflictResolver
{
	private const MAX_ATTEMPTS = 100;

	public function __construct(private readonly int $maxTitleLength = 255)
	{
	}

	/**
	 * @template T
	 * @param callable(string $title): T $saveAttempt
	 * @return T
	 */
	public function tryWithAutoSuffix(callable $saveAttempt, string $title): mixed
	{
		for ($i = 1; $i <= self::MAX_ATTEMPTS; $i++)
		{
			$candidate = $this->buildCandidate($title, $i);
			try
			{
				return $saveAttempt($candidate);
			}
			catch (\Exception $e)
			{
				if (!self::isDuplicateInChain($e))
				{
					throw $e;
				}
				// Duplicate title, try next suffix.
			}
		}

		throw new NameConflictResolutionException(
			"Could not resolve title conflict for '{$title}' within " . self::MAX_ATTEMPTS . ' attempts'
		);
	}

	private function buildCandidate(string $title, int $attempt): string
	{
		if ($attempt === 1)
		{
			return $title;
		}

		$suffix = sprintf(' (%d)', $attempt);
		$baseMaxLength = $this->maxTitleLength - mb_strlen($suffix);

		return mb_substr($title, 0, max(0, $baseMaxLength)) . $suffix;
	}

	private static function isDuplicateInChain(\Throwable $e): bool
	{
		$cur = $e;
		$visited = [];
		while ($cur !== null && !in_array(spl_object_id($cur), $visited, true))
		{
			if ($cur instanceof DuplicateEntryException)
			{
				return true;
			}
			$visited[] = spl_object_id($cur);
			$cur = $cur->getPrevious();
		}

		return false;
	}
}
