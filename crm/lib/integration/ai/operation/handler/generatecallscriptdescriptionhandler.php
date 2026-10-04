<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\AI\Operation\Handler;

use Bitrix\Crm\Copilot\CallAssessment\Controller\CopilotCallAssessmentController;
use Bitrix\Crm\Copilot\CallAssessment\Entity\CopilotCallAssessmentTable;
use Bitrix\Crm\Copilot\CallScriptEditReview\EditReviewRepository;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Dto\Scoring\GenerateCallScriptDescriptionPayload;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;

final class GenerateCallScriptDescriptionHandler
{
	public function __construct(
		private readonly int $assessmentId,
		private readonly GenerateCallScriptDescriptionPayload $payload,
	)
	{
	}

	/**
	 * Applies the generated description to the script the job was launched for and drops the pending
	 * review flag. Returns false when the job is not the one the script waits for.
	 */
	public function applyDescriptionByJob(int $jobId): bool
	{
		if ($this->assessmentId <= 0 || $jobId <= 0)
		{
			return false;
		}

		$reviewRepo = EditReviewRepository::getInstance();
		$expectedJobId = $reviewRepo->getJobId($this->assessmentId);
		if ($expectedJobId === null || $expectedJobId !== $jobId)
		{
			return false;
		}

		$newDescription = trim($this->payload->description ?? '');
		if ($newDescription === '')
		{
			AIManager::logger()->info(
				'{date}: {class}: job {job} returned empty description; clearing review flag',
				['class' => self::class, 'job' => $jobId],
			);
			$reviewRepo->clearIfMatches($this->assessmentId, $jobId);

			return true;
		}

		$controller = CopilotCallAssessmentController::getInstance();
		$current = $controller->getById($this->assessmentId);
		if ($current === null || $current->getDescription() === $newDescription)
		{
			$reviewRepo->clearIfMatches($this->assessmentId, $jobId);

			return true;
		}

		$result = $controller->updateDescription($this->assessmentId, $newDescription);
		if (!$result->isSuccess())
		{
			AIManager::logger()->error(
				'{date}: {class}: DESCRIPTION of call assessment {assessmentId} is left unchanged (job {job});'
				. ' generated description is {descriptionLength} characters long, field limit is {descriptionLimit};'
				. ' the review row is left in place and gets overwritten by the next script edit: {errors}',
				[
					'class' => self::class,
					'assessmentId' => $this->assessmentId,
					'job' => $jobId,
					'descriptionLength' => mb_strlen($newDescription),
					'descriptionLimit' => $this->getDescriptionLengthLimit() ?? 'unknown',
					'errors' => implode('; ', $result->getErrorMessages()),
				],
			);

			return true;
		}

		$reviewRepo->clearIfMatches($this->assessmentId, $jobId);

		return true;
	}

	private function getDescriptionLengthLimit(): ?int
	{
		$field = CopilotCallAssessmentTable::getEntity()->getField('DESCRIPTION');
		foreach ($field->getValidators() as $validator)
		{
			if ($validator instanceof LengthValidator)
			{
				return $validator->getMax();
			}
		}

		return null;
	}
}
