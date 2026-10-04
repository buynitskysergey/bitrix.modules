<?php

use Bitrix\Bizproc\Internal\Entity\Debugger\TraceType;
use Bitrix\Main\Localization\Loc;

/**
 * An activity owning children by definition. The container itself and the whole lifecycle of the children
 * live in {@see CBPActivity}: an ordinary node served by the unified settings panel owns children too, and
 * there is one implementation of that lifecycle for both.
 */
abstract class CBPCompositeActivity extends CBPActivity
{
	protected $readOnlyData = [];

	protected bool $childContainerEnabled = true;

	protected function getChildContainerTraceKey(string $method): string
	{
		return 'CBPCompositeActivity::' . $method;
	}

	protected function getChildContainerTraceReadOnlyData(): array
	{
		return $this->readOnlyData;
	}

	public function setReadOnlyData(array $data)
	{
		$this->readOnlyData = $data;
	}

	public function getReadOnlyData(): array
	{
		return $this->readOnlyData;
	}

	public function pullReadOnlyData()
	{
		$data = $this->readOnlyData;
		$this->readOnlyData = [];

		return $data;
	}

	protected function clearNestedActivities()
	{
		$debugSessionService = $this->workflow?->getRuntime()->getDebugSessionService();

		$currentCount = count($this->arActivities);

		$debugSessionService?->addTrace(
			TraceType::Log,
			'CBPCompositeActivity::clearNestedActivities',
			Loc::getMessage('BPCGCA_DEBUG_TRACE_CLEAR') ?? '',
			[
				'parent_activity' => $this->getName(),
				'activities_to_clear' => $currentCount,
			],
		);

		$this->arActivities = [];

		$debugSessionService?->addTrace(
			TraceType::Log,
			'CBPCompositeActivity::clearNestedActivities',
			Loc::getMessage('BPCGCA_DEBUG_TRACE_CLEAR_DONE') ?? '',
			[
				'parent_activity' => $this->getName(),
				'cleared_count' => $currentCount,
			],
		);
	}

	public static function validateProperties($arTestProperties = [], ?CBPWorkflowTemplateUser $user = null)
	{
		return parent::ValidateProperties($arTestProperties, $user);
	}
}
