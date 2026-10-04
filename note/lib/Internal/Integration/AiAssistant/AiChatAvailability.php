<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Integration\AiAssistant;

use Bitrix\Im\V2\Chat\CopilotChat;
use Bitrix\Im\V2\Integration\AiAssistant\AiAssistantService;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Note\Internal\Configuration;
use Bitrix\Ui\Public\Services\Copilot\CopilotNameService;

/**
 * Single source of truth for whether the BitrixGPT side chat may be offered to the current user,
 * and for the region-aware product name shown in its phrases. Ported from
 * biconnector/lib/Internal/Integration/AiAssistant/BitrixGptChat.php: the flag source differs, and
 * im's own copilot gate is checked on top of it — unlike the BI dashboard, this panel orders a
 * COPILOT chat itself (API-02), so it must not offer a button when that order cannot succeed.
 *
 * Instance-based on purpose: every foreign call sits behind a protected seam, so the verdict is
 * unit-testable without a portal (same pattern as Internal\Service\License\LicenseService).
 */
class AiChatAvailability
{
	/**
	 * Redesign gate of the BitrixGPT v2 rollout, keyed per consumer module on the aiassistant side.
	 */
	private const REDESIGN_OPTION = 'bitrixgpt_v2_available';

	private const FALLBACK_NAME = 'BitrixGPT';

	/**
	 * Condition order is normative and short-circuiting: flag -> modules -> service -> redesign
	 * gate and bot -> im's copilot gate. The flag never replaces the rest of the conditions, it only
	 * precedes them.
	 */
	public function isAvailable(): bool
	{
		if (!$this->isFlagEnabled())
		{
			return false;
		}

		if (!$this->isAiAssistantAvailable() || !$this->isImAvailable())
		{
			return false;
		}

		$service = $this->getAiAssistantService();
		if ($service === null)
		{
			return false;
		}

		// A positive bot id also proves imbot is installed: the service resolves its bot manager only
		// when both aiassistant and imbot are loaded.
		if (!$service->isBitrixGptV2Available(self::REDESIGN_OPTION) || $service->getBotId() <= 0)
		{
			return false;
		}

		return $this->isCopilotChatAvailable();
	}

	/**
	 * Region-aware product name: "BitrixGPT" in CIS zones, "CoPilot" in the west.
	 */
	public function getName(): string
	{
		if (!$this->isUiAvailable())
		{
			return self::FALLBACK_NAME;
		}

		return $this->getCopilotName();
	}

	protected function isFlagEnabled(): bool
	{
		return Configuration::isAiChatEnabled();
	}

	protected function isAiAssistantAvailable(): bool
	{
		return Loader::includeModule('aiassistant');
	}

	protected function isImAvailable(): bool
	{
		return Loader::includeModule('im');
	}

	protected function isUiAvailable(): bool
	{
		return Loader::includeModule('ui');
	}

	/**
	 * im gates the COPILOT chat this panel orders with conditions of its own
	 * (`CopilotChat::checkCopilotAvailability()`): the copilot must be allowed in the portal zone and
	 * switched on in the AI tuning. The bot checked above is the BitrixGPT agent, a different one, so
	 * without this the button would render where every dialog creation ends in an error.
	 *
	 * `checkCopilotAvailability()` itself is deliberately not called: it resolves the bot id through
	 * `AIHelper`, which REGISTERS the copilot bot when it is missing — a write no page render may
	 * trigger. im registers it on the first chat creation anyway, and a failure there lands on the
	 * panel's own error path.
	 */
	protected function isCopilotChatAvailable(): bool
	{
		return CopilotChat::isAvailable() && CopilotChat::isActive();
	}

	/**
	 * Returns the resolved im service, or null when it is not usable. Typed as `?object` so a unit
	 * test can hand back a stub without pulling im in: the instanceof check that makes the type
	 * real lives here, in the seam, and is exactly the part a test cannot exercise anyway.
	 */
	protected function getAiAssistantService(): ?object
	{
		$service = ServiceLocator::getInstance()->get(AiAssistantService::class);

		return $service instanceof AiAssistantService ? $service : null;
	}

	protected function getCopilotName(): string
	{
		return (new CopilotNameService())->getCopilotName();
	}
}
