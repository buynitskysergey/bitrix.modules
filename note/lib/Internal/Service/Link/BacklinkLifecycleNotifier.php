<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Link;

/**
 * [D1] One semantic entry point: "the lifecycle of these source documents changed — refresh the
 * backlinks of every document they point at". Archive, trash and restore all change what a backlink
 * popover reads off a source through its join (its visibility), so their commands call this right
 * after the state change is applied.
 *
 * Kept apart from {@see \Bitrix\Note\Internal\Service\DomainEventPublisher} on purpose: that class is
 * the external boundary for note's domain events, and hanging an internal link-index side effect off
 * it would couple the two. Commands invoke this notifier explicitly instead. Rename has no lifecycle
 * event and is signalled at its own command; hard delete resolves and notifies its targets before its
 * rows vanish (see HardDeleteService), so it does not go through here.
 */
final class BacklinkLifecycleNotifier
{
	private readonly DocumentLinkIndexService $linkIndexService;

	public function __construct(?DocumentLinkIndexService $linkIndexService = null)
	{
		$this->linkIndexService = $linkIndexService ?? new DocumentLinkIndexService();
	}

	/**
	 * @param int[] $sourceIds documents whose lifecycle changed
	 */
	public function sourcesChanged(array $sourceIds): void
	{
		if ($sourceIds === [])
		{
			return;
		}

		// Best-effort: a refresh must never fail a mutation that already committed.
		try
		{
			$this->linkIndexService->notifySourceMetadataChangedForMany($sourceIds);
		}
		catch (\Throwable)
		{
		}
	}
}
