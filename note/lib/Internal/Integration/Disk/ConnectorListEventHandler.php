<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Integration\Disk;

use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Loader;

/**
 * Registers the knowledge base as an owner entity for Disk attachments, so that a file attached to
 * a document asks the document — not the file — who may read it.
 */
final class ConnectorListEventHandler
{
	public static function onBuildAdditionalConnectorList(Event $event): EventResult
	{
		if (!Loader::includeModule('disk'))
		{
			return new EventResult(EventResult::SUCCESS, []);
		}

		return new EventResult(EventResult::SUCCESS, [
			[
				'ENTITY_TYPE' => DocumentConnector::ENTITY_TYPE,
				'MODULE_ID' => 'note',
				'CLASS' => DocumentConnector::class,
			],
		]);
	}
}
