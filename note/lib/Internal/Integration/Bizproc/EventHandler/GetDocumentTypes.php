<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Integration\Bizproc\EventHandler;

use Bitrix\Bizproc\Public\Event\Document\OnGetDocumentTypeEvent\OnGetDocumentTypeEvent;
use Bitrix\Main\EventResult;
use Bitrix\Note\Internal\Integration\Bizproc\Document\NoteDocument;

/**
 * Announces the knowledge base document type to the "document type" field control — the single consumer
 * of this event. Whether the type then shows up is decided by
 * {@see NoteDocument::canUserOperateDocumentType()}, not here: the handler only names the type.
 *
 * The method is named rather than `__invoke` on purpose: registration in a migration takes the pair
 * "class, method".
 */
class GetDocumentTypes
{
	public static function onGetDocumentType(OnGetDocumentTypeEvent $event): void
	{
		$event->addResult(
			new EventResult(
				EventResult::SUCCESS,
				[
					'documentTypes' => [
						NoteDocument::complexDocumentType(),
					],
				],
			),
		);
	}
}
