<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\Intranet;

use Bitrix\Intranet\UI\LeftMenu\Preset\Crm;
use Bitrix\Intranet\UI\LeftMenu\Preset\Manager;
use Bitrix\Main\Loader;

/**
 * Read-only access to the left menu preset the portal runs on.
 *
 * The answer is memoized per instance: a caller may ask the same question several times within
 * one hit, and the instance comes from the ServiceLocator, which keeps it for the whole request.
 * Nothing is cached between hits, so no invalidation is needed.
 *
 * @internal
 */
class LeftMenuPresetRepository
{
	private ?bool $isCrmPreset = null;

	/**
	 * Tells whether the portal is switched to the CRM left menu preset, i.e. is set up for sales.
	 *
	 * @return bool false when the intranet module is unavailable: such a portal has no preset at
	 * all, which is an expected answer rather than an error.
	 */
	public function isCrmPreset(): bool
	{
		return $this->isCrmPreset ??= $this->matchesCrmPreset($this->readPresetCode());
	}

	/**
	 * Returns the code of the current left menu preset of the portal.
	 *
	 * @return string|null null when the intranet module is unavailable.
	 */
	protected function readPresetCode(): ?string
	{
		if (!Loader::includeModule('intranet'))
		{
			return null;
		}

		return Manager::getPreset()->getCode();
	}

	private function matchesCrmPreset(?string $presetCode): bool
	{
		// the preset code constant belongs to intranet, so it stays untouched without the module
		return $presetCode !== null && $presetCode === Crm::CODE;
	}
}
