<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Integration\Intranet\EventHandler;

use Bitrix\Intranet\Public\Event\OnInitSummaryProvidersEvent;
use Bitrix\Note\Internal\Integration\Intranet\AnnualSummaryProvider;

/**
 * Hands note's provider to the annual summary agent. The period comes with the event and is
 * passed on untouched. No module check here: the event is sent by intranet itself.
 */
final class InitSummaryProviders
{
	public static function onInitSummaryProviders(OnInitSummaryProvidersEvent $event): void
	{
		$event->addProvider(new AnnualSummaryProvider($event->getFrom(), $event->getTo()));
	}
}
