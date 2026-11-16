<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Integration\AiAssistant;

use Bitrix\AI\Container;
use Bitrix\AI\Context;
use Bitrix\AI\Engine;
use Bitrix\AI\Services\CopilotAccessCheckerService;
use Bitrix\AI\Tuning;
use Bitrix\Main\Config\Feature;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Loader;
use Bitrix\Note\Internal\Config\Feature\AiEditorFlag;
use Bitrix\Ui\Public\Services\Copilot\CopilotNameService;

/**
 * Single source of truth for whether the AI entry points of the document editor may be offered to
 * the current user, and for the region-aware product name their phrases show (DTO-01). Text and
 * images are independent verdicts: a region can serve text while having no image engine at all.
 *
 * Only the verdicts and the name are published, never the reason behind a refusal: a client able to
 * tell "flag off" from "conditions did not hold" would start to depend on the difference. Document
 * permissions are not checked here: the component's ACL answers them before the verdict is asked.
 *
 * Instance-based on purpose: every foreign call sits behind a protected seam, so the verdict is
 * unit-testable without a portal (same pattern as AiChatAvailability).
 */
class EditorCopilotAvailability
{
	/**
	 * Code of the AI settings switch registered by Internal\Integration\Ai\Settings. It lives here
	 * because this service is its only reader.
	 */
	public const TUNING_CODE_TEXT = 'note_editor_allow_text_generate';

	/**
	 * Prompt category codes the editor asks the ai module for: one for editing, one for the read-only
	 * mode. Declared on this side because the enum of the ai module receives the very same strings
	 * later, and the editor must not wait for it to be able to offer the entry points.
	 */
	public const PROMPT_CATEGORY_EDIT = 'note';
	public const PROMPT_CATEGORY_READONLY = 'readonly_note';

	/**
	 * Shown where ui cannot be asked for the region-aware name. Public because the AI settings item of
	 * Internal\Integration\Ai\Settings falls back to the same string: the switch and the feature it
	 * governs must not call the product differently.
	 */
	public const FALLBACK_NAME = 'BitrixGPT';

	/** @var bool|null the ALG-01 verdict of this instance, resolved within this request only */
	private ?bool $textAvailable = null;

	/**
	 * Asked twice per page: once for the text verdict, once through isImageAvailable(). The chain
	 * behind the verdict is not free either way: it reads the tuning option of the ai module and
	 * asks that module about the copilot access of the user. And a page must not contradict itself,
	 * so the verdict has to be one and the same for the whole request. So the chain runs once per
	 * instance, and the component builds one instance per request. An instance answers the
	 * environment it first saw: for a changed environment build a new service instead of expecting
	 * this one to notice.
	 */
	public function isTextAvailable(): bool
	{
		return $this->textAvailable ??= $this->resolveTextAvailability();
	}

	/**
	 * ALG-01. Condition order is normative and short-circuiting: flag -> ai module -> current user
	 * -> settings switch -> copilot access. The flag never replaces the rest of the conditions, it
	 * only precedes them. The two conditions that cost nothing come first, so a portal without the
	 * feature never reads the tuning option and never asks the ai module about access.
	 */
	private function resolveTextAvailability(): bool
	{
		if (!$this->isFlagEnabled())
		{
			return false;
		}

		if (!$this->isAiAvailable())
		{
			return false;
		}

		// The access checker reads a zero id as "nobody asked" and answers yes, so an unresolved user
		// is refused here instead of being handed over as one. The guard is placed before the reading
		// of the settings switch on purpose: it costs nothing, the switch costs an option read. The
		// verdict is a conjunction of independent predicates without side effects, so their order
		// changes only what is left unread.
		$userId = $this->getCurrentUserId();
		if ($userId <= 0)
		{
			return false;
		}

		// An included module is no guarantee of the API asked of it. An ai build older than the tuning
		// storage reader has no such method at all, and an older container answers the request for the
		// access checker with an exception. Both calls of the ai module sit under one guard, so the
		// editor loses its entry points instead of taking the whole document page down.
		try
		{
			return $this->isSettingsSwitchEnabled() && $this->hasCopilotAccess($userId);
		}
		catch (\Throwable)
		{
			return false;
		}
	}

	/**
	 * Image generation takes everything text takes, plus an image engine of its own.
	 */
	public function isImageAvailable(): bool
	{
		if (!$this->isTextAvailable())
		{
			return false;
		}

		return $this->hasImageEngine();
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

	/**
	 * Read by the editor component to fill DTO-01, so the categories reach the client from here
	 * instead of being spelled out at the call site.
	 */
	public function getEditPromptCategory(): string
	{
		return self::PROMPT_CATEGORY_EDIT;
	}

	public function getReadonlyPromptCategory(): string
	{
		return self::PROMPT_CATEGORY_READONLY;
	}

	protected function isFlagEnabled(): bool
	{
		return Feature::isEnabled(AiEditorFlag::class);
	}

	protected function isAiAvailable(): bool
	{
		return Loader::includeModule('ai');
	}

	protected function isUiAvailable(): bool
	{
		return Loader::includeModule('ui');
	}

	/**
	 * The stored position of the portal switch. No code in the storage means nobody has ever moved
	 * it, and the item is published with `default => true`, so an absent code reads as enabled: that
	 * is the answer Tuning\Manager would give for it, and the opposite would leave the feature dark
	 * on every portal whose AI settings nobody ever opened. Absence has to be told from a stored
	 * `null` by array_key_exists, exactly as the manager tells them apart, because a stored `null`
	 * is a value and reads as off.
	 *
	 * A stored value is normalized the way Bitrix\AI\Tuning\Item::setValue() normalizes a BOOLEAN
	 * item, and `ai/lib/Tuning/Item.php` stays the source of truth: the duplication is the price of
	 * reading the storage instead of the rendered item. A plain (bool) cast is not the same thing:
	 * it would turn the 'N' an administrator stored into "enabled". Anything not scalar (a `null`,
	 * an array left by an older build) reads as off too: a value nobody can interpret must never
	 * stand for permission.
	 */
	private function isSettingsSwitchEnabled(): bool
	{
		$storage = $this->getTuningStorage();
		if (!array_key_exists(self::TUNING_CODE_TEXT, $storage))
		{
			return true;
		}

		$value = $storage[self::TUNING_CODE_TEXT];
		if (!is_scalar($value))
		{
			return false;
		}

		return $value === true
			|| mb_strtolower((string)$value) === 'true'
			|| (string)$value === 'Y'
			|| (int)$value === 1
		;
	}

	/**
	 * The raw tuning storage of the ai module: one module option and a json_decode, without raising
	 * `onTuningLoad` across every listening module and without enumerating engines. Kept as a seam
	 * so a unit test can hand back an array without pulling ai in.
	 */
	protected function getTuningStorage(): array
	{
		return Tuning\Manager::getTuningStorage();
	}

	protected function hasCopilotAccess(int $userId): bool
	{
		/** @var CopilotAccessCheckerService $accessChecker */
		$accessChecker = Container::init()->getItem(CopilotAccessCheckerService::class);

		return $accessChecker->canShowInFrontend($userId);
	}

	protected function hasImageEngine(): bool
	{
		return Engine::getByCategory(Engine::CATEGORIES['image'], Context::getFake()) !== null;
	}

	protected function getCurrentUserId(): int
	{
		return (int)CurrentUser::get()->getId();
	}

	protected function getCopilotName(): string
	{
		return (new CopilotNameService())->getCopilotName();
	}
}
