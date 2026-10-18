<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Setup;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Type\DateTime;
use Bitrix\Note\Internal\Repository\EventRepository;

/**
 * Baselines the drain cursor of every notification profile at the cooled head of the journal, so
 * that the first tick of NotificationDrainAgent delivers only what happened after the install.
 *
 * Without this the agent meets an uninitialized option, baselines it itself and skips the pass -
 * which means everything between the install and that tick (up to the 300 s agent interval) is
 * stepped over and lost for good. Delivery ships enabled, so on a live portal that is the very
 * first subscription and the very first edit people make.
 *
 * Portals that already have the module are not seeded by an update: there the first agent tick
 * baselines the cursor itself and skips that one pass, which loses nothing but the pass. The option
 * names are spelled out because the shipping policy exposes only the profiles it currently ships,
 * and the cursor that most needs a baseline is exactly the one whose profile does not ship yet.
 */
final class NotificationCursorInstaller
{
	/**
	 * Seconds a journal row is left to cool before the cursor may be parked past it. Mirrors
	 * NotificationShippingPolicy::COMMIT_LAG, which is the source of the value: an ID is handed out
	 * at INSERT and becomes visible at COMMIT, so the raw maximum can stand above a row still in
	 * flight, and a cursor parked there steps over that row for good. Spelled out for the reason
	 * the option names are, and pinned to the profiles by the same test.
	 */
	public const COMMIT_LAG = 60;

	/** Cursor option of every drain profile of NotificationShippingPolicy, shipped or not. */
	public const CURSOR_OPTIONS = [
		'event_notify_last_id',
		'event_notify_instant_last_id',
	];

	public function __construct(
		private readonly EventRepository $eventRepository = new EventRepository(),
	) {}

	/**
	 * Written only where the portal never expressed a cursor of its own, which is what makes a
	 * second run - a reinstall over preserved tables, or the updater arriving afterwards - a no-op
	 * instead of a jump forward over undelivered events.
	 */
	public function install(): void
	{
		$journalHead = null;
		foreach (self::CURSOR_OPTIONS as $cursorOption)
		{
			// Empty default on purpose: a stored 0 is a cursor at the head of an empty journal and
			// must not be mistaken for "never initialized" (same read as in the agent).
			if ((string)Option::get('note', $cursorOption, '') !== '')
			{
				continue;
			}

			$journalHead ??= $this->eventRepository->getMaxId(
				DateTime::createFromTimestamp(time() - self::COMMIT_LAG),
			);
			Option::set('note', $cursorOption, (string)$journalHead);
		}
	}
}
