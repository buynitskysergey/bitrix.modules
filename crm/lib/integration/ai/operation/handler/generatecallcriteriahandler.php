<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\AI\Operation\Handler;

use Bitrix\Crm\Copilot\CallAssessment\CallAssessmentItem;
use Bitrix\Crm\Copilot\CallAssessment\Controller\CopilotCallAssessmentController;
use Bitrix\Crm\Copilot\CallAssessment\CriteriaWriter;
use Bitrix\Crm\Copilot\CallAssessment\Enum\AutoCheckType;
use Bitrix\Crm\Copilot\CallAssessment\Enum\CallType;
use Bitrix\Crm\Copilot\CallAssessment\Enum\ClientType;
use Bitrix\Crm\Copilot\CallScriptMaintenance\Dispatcher;
use Bitrix\Crm\Copilot\CallScriptMaintenance\FirstScriptCreatedFlag;
use Bitrix\Crm\Copilot\CallScriptMaintenance\State;
use Bitrix\Crm\Copilot\CallScriptMaintenance\StateRepository;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Dto\Scoring\GenerateCallCriteriaPayload;

final class GenerateCallCriteriaHandler
{
	private const HANDLED_JOB_TYPES = [
		State::JOB_TYPE_CREATE_CALL_ASSESSMENT,
		State::JOB_TYPE_ENRICH_CALL_ASSESSMENT,
		State::JOB_TYPE_BACKFILL_CALL_ASSESSMENT,
	];

	public function __construct(
		private int $assessmentId,
		private readonly GenerateCallCriteriaPayload $payload,
	)
	{

	}

	public function saveCriteria(): void
	{
		if ($this->assessmentId <= 0)
		{
			return;
		}

		$criteria = array_map(
			static fn($criterion): array => [
				'title' => $criterion->name,
				'description' => $criterion->description,
			],
			$this->payload->newCriteria,
		);

		$results = (new CriteriaWriter())->append($this->assessmentId, $criteria);

		foreach ($results as $result)
		{
			if (!$result->isSuccess())
			{
				AIManager::logger()->error(
					'{date}: {class}: Failed to save generated criterion: {errors}',
					[
						'class' => self::class,
						'errors' => $result->getErrors(),
					],
				);
			}
		}
	}

	public function applyMaintenancePostActionsByJob(int $jobId): bool
	{
		$context = (new StateRepository())->popMaintenanceJob(
			$jobId,
			self::HANDLED_JOB_TYPES,
		);

		if ($context === null)
		{
			return false;
		}

		$dispatcher = Dispatcher::getInstance();
		$type = (string)($context['type'] ?? '');
		$selectionIds = $context['selectionIds'] ?? [];

		if ($type === State::JOB_TYPE_CREATE_CALL_ASSESSMENT)
		{
			if (empty($this->payload->newCriteria))
			{
				AIManager::logger()->warning(
					'{date}: {class}: empty criteria — skipping script creation (job {job})',
					['class' => self::class, 'job' => $jobId],
				);

				return true;
			}

			$newId = $this->createScriptFromPayload($context['groupName'] ?? null);
			if ($newId <= 0)
			{
				AIManager::logger()->error(
					'{date}: {class}: failed to create new script from maintenance payload (job {job})',
					['class' => self::class, 'job' => $jobId],
				);

				return true;
			}

			$this->assessmentId = $newId;
			$this->saveCriteria();
			$dispatcher->markGrouped($selectionIds);
			$dispatcher->pingAgent();
		}
		elseif ($type === State::JOB_TYPE_ENRICH_CALL_ASSESSMENT)
		{
			$this->saveCriteria();
			$dispatcher->markEnriched($selectionIds);
			$dispatcher->pingAgent();

			if (!empty($this->payload->newCriteria))
			{
				(new FirstScriptCreatedFlag())->markEnriched($this->assessmentId);
			}
		}
		elseif ($type === State::JOB_TYPE_BACKFILL_CALL_ASSESSMENT)
		{
			$this->saveCriteria();
			$this->backfillAssessmentDescriptionIfEmpty();
		}
		else
		{
			$this->saveCriteria();
		}

		return true;
	}

	private function backfillAssessmentDescriptionIfEmpty(): void
	{
		if ($this->assessmentId <= 0)
		{
			return;
		}

		$newDescription = $this->getPayloadDescription();
		if ($newDescription === '')
		{
			return;
		}

		$controller = CopilotCallAssessmentController::getInstance();
		$current = $controller->getById($this->assessmentId);
		if ($current === null || $current->getDescription() !== '')
		{
			return;
		}

		$result = $controller->updateDescription($this->assessmentId, $newDescription);
		if (!$result->isSuccess())
		{
			AIManager::logger()->error(
				'{date}: {class}: failed to backfill DESCRIPTION for call assessment {assessmentId}: {errors}',
				[
					'class' => self::class,
					'assessmentId' => $this->assessmentId,
					'errors' => implode('; ', $result->getErrorMessages()),
				],
			);
		}
	}

	private function createScriptFromPayload(?string $fallbackName): int
	{
		$title = $this->pickTitle($fallbackName);
		$description = $this->payload->description ?? '';
		$borders = CopilotCallAssessmentController::getInstance()->getMasterBorders();

		$item = CallAssessmentItem::createFromArray([
			'title' => $title,
			'description' => $description,
			'prompt' => '',
			'gist' => null,
			'clientTypeIds' => [ClientType::ANY->value],
			'callTypeId' => CallType::ALL->value,
			'lowBorder' => $borders['lowBorder'] ?? CallAssessmentItem::LOW_BORDER_DEFAULT,
			'highBorder' => $borders['highBorder'] ?? CallAssessmentItem::HIGH_BORDER_DEFAULT,
			'autoCheckTypeId' => AutoCheckType::ALL->value,
		]);

		$addResult = CopilotCallAssessmentController::getInstance()->add($item);
		if (!$addResult->isSuccess())
		{
			AIManager::logger()->error(
				'{date}: {class}: create script failed: {errors}',
				['class' => self::class, 'errors' => $addResult->getErrors()],
			);

			return 0;
		}

		$newId = (int)$addResult->getId();
		(new FirstScriptCreatedFlag())->markCreated($newId);

		return $newId;
	}

	private function getPayloadDescription(): string
	{
		return trim($this->payload->description ?? '');
	}

	private function pickTitle(?string $fallbackName): string
	{
		if ($this->payload->name !== null && $this->payload->name !== '')
		{
			return $this->payload->name;
		}

		if (is_string($fallbackName) && $fallbackName !== '')
		{
			return $fallbackName;
		}

		return 'Auto-generated call script';
	}
}
