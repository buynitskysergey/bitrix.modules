<?php

namespace Bitrix\Crm\Integration\AI\Operation\Autostart;

use Bitrix\Crm\Copilot\CallAssessment\Entity\CopilotCallAssessmentTable;
use Bitrix\Crm\Copilot\CallAssessment\Enum\AutoCheckType;
use Bitrix\Crm\Integration\AI\Operation\Autostart\Slider\AutomationScenarioRegistry;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Web\Json;

final class CallAssessmentDefault
{
	public const OPTION_NAME = 'CALL_SCORING_V2_ASSESSMENT_DEFAULT';

	private const MODULE_ID = 'crm';

	private ?array $cachedDefault = null;
	private bool $isDefaultLoaded = false;

	public function __construct(
		private readonly AutomationScenarioRegistry $registry = new AutomationScenarioRegistry(),
	)
	{
	}

	public function computeAndStore(): void
	{
		if ($this->isStored())
		{
			return;
		}

		$default = [
			'enabled' => $this->computeEnabled($this->fetchActiveAutoCheckTypes()),
			'mode' => $this->registry->getDefaultMode(AutomationScenarioRegistry::CHANNEL_CALL),
		];

		Option::set(self::MODULE_ID, self::OPTION_NAME, Json::encode($default));
	}

	public function clear(): void
	{
		Option::delete(self::MODULE_ID, ['name' => self::OPTION_NAME]);

		$this->cachedDefault = null;
		$this->isDefaultLoaded = false;
	}

	public function get(): ?array
	{
		if ($this->isDefaultLoaded)
		{
			return $this->cachedDefault;
		}

		$this->cachedDefault = $this->load();
		$this->isDefaultLoaded = true;

		return $this->cachedDefault;
	}

	public function isStored(): bool
	{
		return Option::getRealValue(self::MODULE_ID, self::OPTION_NAME, '') !== null;
	}

	private function load(): ?array
	{
		$stored = Option::get(self::MODULE_ID, self::OPTION_NAME, '');
		if ($stored === '')
		{
			return null;
		}

		try
		{
			$decoded = Json::decode($stored);
		}
		catch (ArgumentException)
		{
			return null;
		}

		return is_array($decoded) ? $decoded : null;
	}

	/**
	 * @param int[] $autoCheckTypes AUTO_CHECK_TYPE values of active (IS_ENABLED = true) scripts
	 */
	public function computeEnabled(array $autoCheckTypes): bool
	{
		if ($autoCheckTypes === [])
		{
			return false;
		}

		foreach ($autoCheckTypes as $autoCheckType)
		{
			if ($autoCheckType === AutoCheckType::DISABLED->value)
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * @return int[]
	 */
	private function fetchActiveAutoCheckTypes(): array
	{
		$rows = CopilotCallAssessmentTable::getList([
			'select' => ['AUTO_CHECK_TYPE'],
			'filter' => ['=IS_ENABLED' => true],
		]);

		$autoCheckTypes = [];
		foreach ($rows as $row)
		{
			$autoCheckTypes[] = (int)$row['AUTO_CHECK_TYPE'];
		}

		return $autoCheckTypes;
	}
}
