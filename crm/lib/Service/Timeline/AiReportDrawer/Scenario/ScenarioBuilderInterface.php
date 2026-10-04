<?php

namespace Bitrix\Crm\Service\Timeline\AiReportDrawer\Scenario;

use Bitrix\Crm\Service\Timeline\AiReportDrawer\Dto\ActivityContext;
use Bitrix\Main\Result;

interface ScenarioBuilderInterface
{
	public function getCode(): ScenarioCode;

	public function build(ActivityContext $context): Result;
}
