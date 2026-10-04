<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\License;

use Bitrix\Intranet\Settings\Tools\ToolsManager;
use Bitrix\Main\Loader;

class LicenseService
{
	public const FEATURE_ACCESS_PERMISSIONS = 'limit_note_access_permissions';

	public const SLIDER_ACCESS_PERMISSIONS = 'limit_v2_note_access_permissions';

	public const SLIDER_TOOL_DISABLED = 'limit_note_base_off';

	public const TOOL_MENU_ID = 'menu_note_base';

	public function isModuleAvailable(): bool
	{
		if (!$this->isBitrix24Available())
		{
			return true;
		}

		return $this->isFeatureEnabled(self::FEATURE_ACCESS_PERMISSIONS);
	}

	public function isToolEnabled(): bool
	{
		// Without the intranet module there is no tool registry — treat the tool as enabled.
		if (!$this->isIntranetAvailable())
		{
			return true;
		}

		return $this->getToolsManager()->checkAvailabilityByMenuId(self::TOOL_MENU_ID);
	}

	/**
	 * Single source of truth for tariff/tool blocking. Order is normative:
	 * the feature (tariff) takes precedence over the tool being turned off.
	 */
	public function resolveBlockingSliderCode(): ?string
	{
		if (!$this->isModuleAvailable())
		{
			return self::SLIDER_ACCESS_PERMISSIONS;
		}

		if (!$this->isToolEnabled())
		{
			return self::SLIDER_TOOL_DISABLED;
		}

		return null;
	}

	public function isAccessBlocked(): bool
	{
		return $this->resolveBlockingSliderCode() !== null;
	}

	public function getAccessSliderCode(): string
	{
		return self::SLIDER_ACCESS_PERMISSIONS;
	}

	public function getToolDisabledSliderCode(): string
	{
		return self::SLIDER_TOOL_DISABLED;
	}

	protected function isBitrix24Available(): bool
	{
		return Loader::includeModule('bitrix24');
	}

	protected function isFeatureEnabled(string $featureId): bool
	{
		return \Bitrix\Bitrix24\Feature::isFeatureEnabled($featureId);
	}

	protected function isIntranetAvailable(): bool
	{
		return Loader::includeModule('intranet');
	}

	protected function getToolsManager(): ToolsManager
	{
		return ToolsManager::getInstance();
	}
}
