<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Integration\Ai;

use Bitrix\AI\Context;
use Bitrix\AI\Engine;
use Bitrix\AI\Tuning;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ORM\EventResult;
use Bitrix\Note\Internal\Integration\AiAssistant\EditorCopilotAvailability;
use Bitrix\Ui\Public\Services\Copilot\CopilotNameService;

/**
 * ai:onTuningLoad handler, registered in install/migrations/events.php. Publishes the single portal
 * switch that lets an administrator take AI out of the knowledge base without turning AI off for the
 * whole portal. The values live in the tuning option of the ai module, so note keeps no storage of
 * its own for it.
 *
 * The switch is on by default: it turns a shipped feature off, not on. Whether the feature exists at
 * all is answered by the note feature flag, and the only reader of this switch is
 * EditorCopilotAvailability, which also owns its code.
 *
 * Every foreign call sits behind a protected seam, so the published item is unit-testable without a
 * portal (same pattern as EditorCopilotAvailability).
 */
class Settings
{
	private const SORT = 800;

	private const PHRASE_LANGUAGE_FALLBACK = 'ru';

	public static function onTuningLoad(): EventResult
	{
		$result = new EventResult();
		$result->modifyFields([
			'items' => (new static())->getItems(),
			'groups' => [],
		]);

		return $result;
	}

	/**
	 * Empty without a text engine: a switch the region cannot serve would be a dead row in the AI
	 * settings. Same gate tasks, calendar, catalog, mail and landing put on their own items.
	 *
	 * An unpublished item also loses its stored value on the next save of the AI settings, because
	 * Tuning\Manager::save() rewrites the whole storage from the items published in that request.
	 * Accepted for this gate: without a text engine CopilotAccessCheckerService refuses the verdict
	 * on its own engine check, so the switch governs nothing until an engine comes back. For the
	 * other way an item gets dropped -- a title that is not a string -- see getPhrase() below.
	 */
	protected function getItems(): array
	{
		if (!$this->hasTextEngine())
		{
			return [];
		}

		return [
			EditorCopilotAvailability::TUNING_CODE_TEXT => [
				'group' => Tuning\Defaults::GROUP_TEXT,
				'title' => (string)$this->getPhrase('NOTE_AI_SETTINGS_COPILOT_TITLE'),
				'header' => (string)$this->getPhrase(
					'NOTE_AI_SETTINGS_ALLOW_TEXT_COPILOT_DESC',
					[
						'#COPILOT_NAME#' => $this->getCopilotName(),
					],
				),
				'type' => Tuning\Type::BOOLEAN,
				'default' => true,
				'sort' => self::SORT,
			],
		];
	}

	/**
	 * Loc answers null for a language it has no file for, and substitutes ru only within the CIS
	 * region. An item whose title is not a string is dropped by Tuning\Manager without a word, and the
	 * next save of the AI settings then wipes the stored switch along with it, so the item has to exist
	 * in every language even where the phrase does not. Has to stay in this file: Loc looks for the
	 * lang file next to the caller of getMessage.
	 */
	private function getPhrase(string $code, ?array $replace = null): ?string
	{
		return Loc::getMessage($code, $replace)
			?? Loc::getMessage($code, $replace, self::PHRASE_LANGUAGE_FALLBACK);
	}

	protected function hasTextEngine(): bool
	{
		return Engine::getByCategory(Engine::CATEGORIES['text'], Context::getFake()) !== null;
	}

	/**
	 * The same name the editor shows, so the setting and the feature it governs call the product
	 * alike in every region. Resolved here and not through EditorCopilotAvailability: this handler
	 * runs on every build of Tuning\Manager anywhere on the portal, and a product name is no reason
	 * to raise the verdict service.
	 */
	protected function getCopilotName(): string
	{
		return Loader::includeModule('ui')
			? (new CopilotNameService())->getCopilotName()
			: EditorCopilotAvailability::FALLBACK_NAME;
	}
}
