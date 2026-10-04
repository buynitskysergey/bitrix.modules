<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Feature\AiAgent;

use Bitrix\Intranet\Infrastructure\Update\Menu\AiAgentMenuConverter;
use Bitrix\Main\Config\Feature\AbstractFlag;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Loader;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final class AiAgentSectionFlag extends AbstractFlag
{
	private const LOGGER_ID = 'bizproc.feature.ai_agent_section';

	// The stepper walks users in batches, and a zero delay would run the first batch inside the
	// request that switched the flag on.
	private const MENU_CONVERSION_DELAY = 60;

	/**
	 * A menu a user has already reordered keeps the section where it was, so opening the section
	 * schedules the stepper that moves the item in the menus already saved. The stepper itself
	 * skips a user whose menu is in order, which is what makes repeated switching safe.
	 *
	 * Nothing may escape: the package does not catch what a hook throws, and the switch happens in
	 * the middle of foreign scenarios that have their own work left to do after it.
	 */
	protected function onEnable(): void
	{
		try
		{
			if (Loader::includeModule('intranet') && class_exists(AiAgentMenuConverter::class))
			{
				AiAgentMenuConverter::bind(self::MENU_CONVERSION_DELAY);
			}
		}
		catch (\Throwable $exception)
		{
			$this->getLogger()->error(
				'Cannot schedule the left menu conversion of the AI agent section: {message}',
				['message' => $exception->getMessage()],
			);
		}
	}

	private function getLogger(): LoggerInterface
	{
		return (new LoggerFactory())->createById(self::LOGGER_ID) ?? new NullLogger();
	}
}
