<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Public\Type\CustomTemplate;

/**
 * Labelled grid-filter dropdown data for a zone, produced by the zone provider from the same
 * in-memory readable-target set as {@see ReadableScope} (no DB query). Only the grid uses it;
 * the selector has no dropdown and never builds these labels.
 */
final class ReadableFilterOptions
{
	/**
	 * @param FilterSceneOption[] $scenes
	 * @param FilterSubjectOption[] $subjects
	 */
	public function __construct(
		public readonly array $scenes,
		public readonly array $subjects,
	)
	{
	}
}
