<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Controller;

use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Note\Internal\Integration\AiAssistant\AiChatAvailability;
use Bitrix\Note\Internal\Integration\AiAssistant\WidgetApplicationDataResolver;

/**
 * [API-01] Application data for the BitrixGPT side chat widget. The class name is part of the
 * action id (`note.infrastructure.AiChatController.getWidgetConfig`) — renaming it breaks the
 * front-end call.
 *
 * The action does NOT create the dialog: that stays a client-side im.v2.Chat.add call (API-02).
 */
class AiChatController extends Controller
{
	protected function getDefaultPreFilters(): array
	{
		// note_access is the section-wide gate the page itself already passes. Without it this
		// endpoint would hand messenger application data to a user with no knowledge-base access.
		return array_merge(
			parent::getDefaultPreFilters(),
			[
				new ActionFilter\NoteAccess(),
			],
		);
	}

	public function getWidgetConfigAction(): ?array
	{
		// The single source of the verdict; the action keeps no copy of the conditions. Refusing
		// here is mandatory: otherwise the endpoint becomes a way around the feature flag.
		if (!$this->createAiChatAvailability()->isAvailable())
		{
			$this->addError(new Error(Loc::getMessage('NOTE_AI_CHAT_UNAVAILABLE'), 'AI_CHAT_UNAVAILABLE'));

			return null;
		}

		$config = $this->createWidgetApplicationDataResolver()->resolve();
		if ($config === [])
		{
			$this->addError(
				new Error(Loc::getMessage('NOTE_AI_CHAT_CONFIG_UNAVAILABLE'), 'AI_CHAT_CONFIG_UNAVAILABLE'),
			);

			return null;
		}

		return ['config' => $config];
	}

	protected function createAiChatAvailability(): AiChatAvailability
	{
		return new AiChatAvailability();
	}

	protected function createWidgetApplicationDataResolver(): WidgetApplicationDataResolver
	{
		return new WidgetApplicationDataResolver();
	}
}
