<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\LastValues;

use Bitrix\Bizproc\Internal\Model\LastValues\WorkflowLastValuesTable;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Event;
use Psr\Log\LoggerInterface;

/**
 * Keeps the snapshot from outliving the template it belongs to. Never throws: a snapshot is derived
 * data and its loss must not break the deletion of the template, which the event is only a part of.
 */
class EventHandler
{
	private const LOGGER_ID = 'bizproc.last_values';

	public static function onAfterWorkflowTemplateDelete(Event $event): void
	{
		$templateId = (int)$event->getParameter('ID');
		if ($templateId <= 0)
		{
			return;
		}

		try
		{
			WorkflowLastValuesTable::delete($templateId);
		}
		catch (\Throwable $exception)
		{
			self::getLogger()?->warning(
				'Bizproc last values deletion failed for template {templateId}: {message}',
				[
					'templateId' => $templateId,
					'message' => $exception->getMessage(),
				],
			);
		}
	}

	/**
	 * Null while the logger is switched off in the registry: there is nothing to write the diagnostics to,
	 * so the deletion skips it instead of feeding a NullLogger.
	 */
	private static function getLogger(): ?LoggerInterface
	{
		return (new LoggerFactory(alwaysReturnLogger: false))->createById(self::LOGGER_ID);
	}
}
