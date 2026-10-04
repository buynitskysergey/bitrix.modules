<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\Pilot;

use Bitrix\Bizproc\Internal\Model\Pilot\WorkflowTemplatePilotTable;

/**
 * Whether the portal has anything to execute - a pilot version stored for any template. The routing of a
 * start and the cascade of the stop skip themselves whole while the answer is no.
 *
 * The snapshot of a pilot is what is asked about, so the answer turns to no the moment the last pilot is
 * stopped. The question "is there anything to hide" has a life of its own - the mark of a publication
 * outlives the snapshot - and is answered by {@see RestrictedTemplateArea}.
 *
 * The mechanics of the cache and of its invalidation are in {@see PilotPortalCache}.
 */
final class PilotPresence extends PilotPortalCache
{
	private const CACHE_ID = 'bizproc_pilot_presence';
	private const PRESENCE_OPTION = 'pilot_presence';

	public function hasAnyPilot(): bool
	{
		return (bool)($this->value()['hasAny'] ?? false);
	}

	protected function cacheId(): string
	{
		return self::CACHE_ID;
	}

	protected function presenceOptionName(): string
	{
		return self::PRESENCE_OPTION;
	}

	protected function emptyValue(): array
	{
		return ['hasAny' => false];
	}

	protected function hasStoredValue(array $value): bool
	{
		return (bool)($value['hasAny'] ?? false);
	}

	/**
	 * Existence and not a count: nobody asks how many pilots the portal runs, and a row of the index is
	 * cheaper than counting the whole table.
	 */
	protected function readFromStorage(): array
	{
		$row = WorkflowTemplatePilotTable::query()
			->setSelect(['ID'])
			->setLimit(1)
			->fetch()
		;

		return ['hasAny' => $row !== false];
	}
}
