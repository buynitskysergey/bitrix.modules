<?php

namespace Bitrix\Crm\Service\Timeline\AiReportDrawer\Presentation;

use Bitrix\Crm\Service\Timeline\AiReportDrawer\Dto\ActivityContext;

interface ActivityPresentationBuilderInterface
{
	public function supports(ActivityContext $context): bool;

	public function buildSubtitle(ActivityContext $context): ?array;

	public function buildInfoPopup(ActivityContext $context): ?array;
}
