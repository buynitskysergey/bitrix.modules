<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\AiAgentGrid;

use Bitrix\Bizproc\Integration\ImBot\BizprocBot;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;

class ChatbotDeleteService
{
	public const ERROR_BOT_ID_INVALID = 'AI_AGENT_BOT_ID_INVALID';

	/**
	 * The modules that own the bots are not installed, so nothing is proven about the bot.
	 */
	public const ERROR_MODULES_UNAVAILABLE = 'AI_AGENT_BOT_MODULES_UNAVAILABLE';

	public const ERROR_BOT_CLASS_UNAVAILABLE = 'AI_AGENT_BOT_CLASS_UNAVAILABLE';

	public const ERROR_UNREGISTER_FAILED = 'AI_AGENT_BOT_UNREGISTER_FAILED';

	/**
	 * Unregisters bots by already resolved IDs (see {@see AgentChatbotsExtractor::getCreatedBotIds()}).
	 *
	 * @param array{bizproc?: list<int>, openlines?: list<int>} $botIds
	 */
	public function deleteBots(array $botIds): void
	{
		$bizprocIds = $botIds[AgentChatbotsExtractor::KIND_BIZPROC] ?? [];
		$openLinesIds = $botIds[AgentChatbotsExtractor::KIND_OPENLINES] ?? [];

		if (empty($bizprocIds) && empty($openLinesIds))
		{
			return;
		}

		if (!$this->areBotModulesAvailable())
		{
			return;
		}

		$this->unregisterBots(AgentChatbotsExtractor::KIND_BIZPROC, $bizprocIds);
		$this->unregisterBots(AgentChatbotsExtractor::KIND_OPENLINES, $openLinesIds);
	}

	/**
	 * Strict counterpart of {@see self::deleteBots()} for the managed system AI agent lifecycle.
	 *
	 * The optional deletion of the grid stays best effort and keeps swallowing every failure, while the
	 * lifecycle needs a checkable result: an unavailable bot module must not be read as a deleted bot, so it
	 * has an error code of its own. The absence of the bot is answered by the caller, which resolves the
	 * reserved code before it asks for a deletion.
	 *
	 * @param string $kind bot kind of {@see AgentChatbotsExtractor}
	 */
	public function deleteOwnedBot(string $kind, int $botId): Result
	{
		$result = new Result();

		if ($botId <= 0)
		{
			return $result->addError(new Error('Bot id is not usable', self::ERROR_BOT_ID_INVALID));
		}

		if (!$this->areBotModulesAvailable())
		{
			return $result->addError(new Error('Bot modules are not available', self::ERROR_MODULES_UNAVAILABLE));
		}

		$botClass = $this->resolveBotClass($kind);
		if ($botClass === null)
		{
			return $result->addError(new Error('Bot class is not available', self::ERROR_BOT_CLASS_UNAVAILABLE));
		}

		return $botClass::unRegister($botId)
			? $result
			: $result->addError(new Error('Bot was not unregistered', self::ERROR_UNREGISTER_FAILED))
		;
	}

	/**
	 * @return class-string
	 */
	protected function getBizprocBotClass(): string
	{
		return BizprocBot::class;
	}

	/**
	 * @return class-string
	 */
	protected function getOpenLinesBotClass(): string
	{
		return AgentChatbotsExtractor::OPENLINES_BOT_CLASS;
	}

	protected function areBotModulesAvailable(): bool
	{
		return Loader::includeModule('im') && Loader::includeModule('imbot');
	}

	/**
	 * Bot class exposing a static unRegister(int) method, or null when this build cannot delete such a bot.
	 *
	 * @return class-string|null
	 */
	private function resolveBotClass(string $kind): ?string
	{
		$botClass = match ($kind)
		{
			AgentChatbotsExtractor::KIND_BIZPROC => $this->getBizprocBotClass(),
			AgentChatbotsExtractor::KIND_OPENLINES => $this->getOpenLinesBotClass(),
			default => null,
		};

		return $botClass !== null && class_exists($botClass) ? $botClass : null;
	}

	/**
	 * @param string $kind bot kind of {@see AgentChatbotsExtractor}
	 * @param list<int> $botIds
	 */
	private function unregisterBots(string $kind, array $botIds): void
	{
		$botClass = $this->resolveBotClass($kind);
		if ($botClass === null)
		{
			return;
		}

		foreach ($botIds as $botId)
		{
			$id = (int)$botId;
			if ($id > 0)
			{
				$botClass::unRegister($id);
			}
		}
	}
}
