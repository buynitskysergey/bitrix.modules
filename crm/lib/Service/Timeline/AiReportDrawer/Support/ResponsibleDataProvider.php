<?php

namespace Bitrix\Crm\Service\Timeline\AiReportDrawer\Support;

use Bitrix\Crm\Copilot\AiQualityAssessment\Controller\AiQualityAssessmentController;
use Bitrix\Crm\Service\Broker\User;

final class ResponsibleDataProvider
{
	public function getById(int $responsibleId): ?array
	{
		if ($responsibleId <= 0)
		{
			return null;
		}

		$responsible = (new User())->getById($responsibleId);
		$aiQualityAssessmentController = AiQualityAssessmentController::getInstance();

		return [
			'id' => $responsibleId,
			'name' => $responsible['FORMATTED_NAME'] ?? null,
			'photoUrl' => $responsible['PHOTO_URL'] ?? null,
			'profileUrl' => $responsible['SHOW_URL']?->getPath(),
			'rating' => $aiQualityAssessmentController->getPrevAvgAssessmentValue($responsibleId),
		];
	}
}
