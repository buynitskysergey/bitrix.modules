<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Link;

/**
 * [D1] One semantic entry point for document metadata and lifecycle changes. It refreshes both
 * directions of every indexed link: backlink readers of this source and open documents that contain
 * a mention of this document as their target.
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

		try
		{
			$this->linkIndexService->notifySourceMetadataChangedForMany($sourceIds);
		}
		catch (\Throwable)
		{
		}

		try
		{
			$this->linkIndexService->notifyMentionTargetsChanged($sourceIds);
		}
		catch (\Throwable)
		{
		}
	}
}
