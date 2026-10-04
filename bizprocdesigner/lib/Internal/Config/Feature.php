<?php

namespace Bitrix\BizprocDesigner\Internal\Config;

use Bitrix\Bizproc\Internal\Service\Feature\AiAgentsFeature;
use Bitrix\Bizproc\Public\Service\AiAgent\RegionAvailabilityService;
use Bitrix\Bizproc\Public\Service\LastValues\LastValuesService;
use Bitrix\Bizproc\Public\Service\WorkflowTemplate\PilotEditorStateService;
use Bitrix\Main;
use Bitrix\Main\DI\Exception\CircularDependencyException;
use Bitrix\Main\DI\Exception\ServiceNotFoundException;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\BizprocDesigner\Internal\Trait\SingletonTrait;
use Bitrix\Main\ObjectNotFoundException;

class Feature
{
	use SingletonTrait;

	private const MODULE_NAME = 'bizprocdesigner';

	public function isAiAssistantAvailable(): bool
	{
		return $this->getOptionValue('ai_assistant_available', 'N') === 'Y';
	}

	public function isExternalAiAgentAvailable(): bool
	{
		return $this->getOptionValue('external_ai_agent_available', 'N') === 'Y';
	}

	public function areComplexNodeConnectionsAvailable(): bool
	{
		return $this->getOptionValue('complex_node_connections_available', 'N') === 'Y';
	}

	public function isDebugBarAvailable(): bool
	{
		return $this->getOptionValue('debugger_available', 'N') === 'Y';
	}

	/**
	 * Closes only the history interface: capturing the versions themselves is governed by the option of
	 * the bizproc module and is switched on earlier.
	 */
	public function isVersionHistoryAvailable(): bool
	{
		return $this->getOptionValue('version_history_available', 'N') === 'Y';
	}

	public function isNodeFilterAvailable(): bool
	{
		return $this->getOptionValue('node_filter_available', 'N') === 'Y';
	}

	public function areDataTablesAvailable(): bool
	{
		return Main\Config\Option::get('bizproc', 'dataview_enabled', 'N') === 'Y';
	}

	public function isExpressionBuilderAvailable(): bool
	{
		return $this->getOptionValue('expression_builder_available', 'N') === 'Y';
	}

	public function isReadableExpressionsAvailable(): bool
	{
		return $this->getOptionValue('readable_expressions_available', 'N') === 'Y';
	}

	/**
	 * The editor has no flag of its own here: showing the values of the last run makes sense only while
	 * bizproc really captures them, so both sides follow the single option of that module.
	 */
	public function isLastRunValuesAvailable(): bool
	{
		return Main\Loader::includeModule('bizproc') && (new LastValuesService())->isAvailable();
	}

	/**
	 * The editor has no flag of its own here either: the choice of the audience may only be offered while
	 * the module of the processes really accepts a pilot publication, so both sides follow the single
	 * source of that flag instead of reading the option twice.
	 */
	public function isPilotPublicationAvailable(): bool
	{
		return Main\Loader::includeModule('bizproc') && (new PilotEditorStateService())->isFeatureEnabled();
	}

	public function getAgentTokenTtl(): int
	{
		$ttl = (int)Main\Config\Option::get(self::MODULE_NAME, 'agent_token_ttl', 86400);

		// A non-positive TTL would make the token expire at or before creation (expire_at <= now),
		// breaking the whole lifecycle; fall back to the safe default for such invalid overrides.
		if ($ttl <= 0)
		{
			return 86400;
		}

		return $ttl;
	}

	private function getOptionValue(string $option, mixed $defaultValue): ?string
	{
		return Main\Config\Option::get(self::MODULE_NAME, $option, $defaultValue);
	}

	/**
	 * @return list<string>
	 */
	public function getAvailableFeatureCodes(): array
	{
		$featureCodes = [];
		if ($this->isAiAssistantAvailable())
		{
			$featureCodes[] = 'aiAssistant';
		}

		if ($this->areComplexNodeConnectionsAvailable())
		{
			$featureCodes[] = 'complexNodeConnections';
		}

		if ($this->isDebugBarAvailable())
		{
			$featureCodes[] = 'debugBar';
		}

		if ($this->areDataTablesAvailable())
		{
			$featureCodes[] = 'dataTables';
		}

		if ($this->isExternalAiAgentAvailable())
		{
			$featureCodes[] = 'externalAiAgent';
		}

		if ($this->isExpressionBuilderAvailable())
		{
			$featureCodes[] = 'expressionBuilder';
		}

		if ($this->isReadableExpressionsAvailable())
		{
			$featureCodes[] = 'readableExpressions';
		}

		if ($this->isLastRunValuesAvailable())
		{
			$featureCodes[] = 'lastRunValues';
		}

		if ($this->isVersionHistoryAvailable())
		{
			$featureCodes[] = 'versionHistory';
		}

		if ($this->isPilotPublicationAvailable())
		{
			$featureCodes[] = 'pilotPublication';
		}

		return $featureCodes;
	}

	/**
	 * @return list<string>
	 */
	public function getLockedFeatureCodes(): array
	{
		$lockedCodes = [];

		if ($this->isExternalAiAgentAvailable() && !$this->isExternalAiAgentUnlocked())
		{
			$lockedCodes[] = 'externalAiAgent';
		}

		return $lockedCodes;
	}

	/**
	 * The single server-side gate of the external AI agent feature: the rollout option is enabled AND
	 * the feature is unlocked for the portal (AI tariff + region). The REST availability filter and the
	 * agent-token issuance controller call it so a disabled feature is refused on the server, not merely
	 * hidden in the UI.
	 *
	 * @throws CircularDependencyException
	 * @throws ObjectNotFoundException
	 */
	public function isExternalAiAgentAccessible(): bool
	{
		return $this->isExternalAiAgentAvailable() && $this->isExternalAiAgentUnlocked();
	}

	/**
	 * @throws CircularDependencyException
	 * @throws ObjectNotFoundException
	 */
	private function isExternalAiAgentUnlocked(): bool
	{
		try
		{
			$aiAgentsFeature = ServiceLocator::getInstance()->get(AiAgentsFeature::class);
		}
		catch (ServiceNotFoundException)
		{
			$aiAgentsFeature = null;
		}

		if ($aiAgentsFeature !== null && !$aiAgentsFeature->isAvailable())
		{
			return false;
		}

		try
		{
			$regionService = ServiceLocator::getInstance()->get(RegionAvailabilityService::class);
		}
		catch (ServiceNotFoundException)
		{
			$regionService = null;
		}

		if ($regionService !== null && !$regionService->isAvailable())
		{
			return false;
		}

		return true;
	}
}