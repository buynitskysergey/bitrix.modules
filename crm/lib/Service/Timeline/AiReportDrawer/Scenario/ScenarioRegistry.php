<?php

namespace Bitrix\Crm\Service\Timeline\AiReportDrawer\Scenario;

final class ScenarioRegistry
{
	/** @var array<string, ScenarioBuilderInterface> */
	private array $builders = [];

	public function __construct(ScenarioBuilderInterface ...$builders)
	{
		foreach ($builders as $builder)
		{
			$this->builders[$builder->getCode()->value] = $builder;
		}
	}

	public function getByCode(ScenarioCode $code): ?ScenarioBuilderInterface
	{
		return $this->builders[$code->value] ?? null;
	}
}
