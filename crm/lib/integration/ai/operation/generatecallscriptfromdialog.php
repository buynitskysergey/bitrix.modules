<?php

namespace Bitrix\Crm\Integration\AI\Operation;

use Bitrix\AI\Context;
use Bitrix\Crm\Copilot\CallAssessment\CallAssessmentItem;
use Bitrix\Crm\Copilot\CallAssessment\Controller\CopilotCallAssessmentController;
use Bitrix\Crm\Copilot\CallAssessment\Entity\CopilotCallAssessmentTable;
use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Dto\Scoring\CallScriptCreatorPayload;
use Bitrix\Crm\Integration\AI\EventHandler;
use Bitrix\Crm\Integration\AI\Model\EO_Queue;
use Bitrix\Crm\Integration\AI\Operation\Handler\CallScriptCreatorHandler;
use Bitrix\Crm\Integration\AI\Operation\Payload\PayloadFactory;
use Bitrix\Crm\Integration\AI\Result;
use Bitrix\Crm\Integration\Analytics\Builder\AI\AIBaseEvent;
use Bitrix\Crm\Integration\Analytics\Builder\AI\GenerateCallScriptFromDialogEvent;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Container;
use Bitrix\Main;
use CCrmOwnerType;

final class GenerateCallScriptFromDialog extends AbstractOperation
{
	public const TYPE_ID = 13;
	public const CONTEXT_ID = 'generate_call_script_from_dialog';

	protected const PAYLOAD_CLASS = CallScriptCreatorPayload::class;
	protected const ENGINE_CODE = EventHandler::SETTINGS_CALL_ASSESSMENT_ENGINE_CODE;

	private string $userText = '';

	public function __construct(int $assessmentId, ?int $userId = null, ?int $parentJobId = null)
	{
		parent::__construct(
			new ItemIdentifier(CCrmOwnerType::CopilotCallAssessment, $assessmentId),
			$userId,
			$parentJobId,
		);
	}

	public static function isAccessGranted(int $userId, ItemIdentifier $target): bool
	{
		if ($userId <= 0)
		{
			return false;
		}

		return Container::getInstance()->getUserPermissions($userId)->copilotCallAssessment()->canEdit();
	}

	public static function isSuitableTarget(ItemIdentifier $target): bool
	{
		return $target->getEntityTypeId() === CCrmOwnerType::CopilotCallAssessment
			&& $target->getEntityId() > 0
		;
	}

	public function setUserText(string $text): self
	{
		$this->userText = $text;

		return $this;
	}

	protected static function checkPreviousJobs(ItemIdentifier $target, int $parentId): Main\Result
	{
		return new Main\Result();
	}

	protected function getAIPayload(): Main\Result
	{
		$additionalData = [
			'userText' => $this->userText,
			'assessmentSettingsId' => $this->target->getEntityId(),
		];

		return PayloadFactory::build(self::TYPE_ID, $this->userId, $this->target)
			->setAdditionalData($additionalData)
			->setMarkers([])
			->getResult()
		;
	}

	// region notify
	protected static function notifyTimelineAfterSuccessfulLaunch(Result $result): void
	{
	}

	protected static function notifyTimelineAfterSuccessfulJobFinish(Result $result): void
	{
	}

	protected static function notifyAboutJobError(
		Result $result,
		bool $withSyncBadges = true,
		bool $withSendAnalytics = true,
		?ItemIdentifier $target = null,
	): void
	{
		AIManager::logger()->error(
			'{date}: {class}: Job error on target: {target}' . PHP_EOL,
			[
				'class' => self::class,
				'target' => $result->getTarget(),
			],
		);

		$retryCount = $result->getRetryCount();
		$isFinalFailure = $retryCount === null || $retryCount >= Result::MAX_RETRY_COUNT;
		if (!$isFinalFailure)
		{
			return;
		}

		$assessmentId = (int)($result->getTarget()?->getEntityId() ?? 0);
		if ($assessmentId <= 0)
		{
			return;
		}

		$isGenerating = CopilotCallAssessmentTable::query()
			->setSelect(['ID'])
			->where('ID', $assessmentId)
			->where('STATUS', CallAssessmentItem::STATUS_GENERATING_FROM_DIALOG)
			->fetch()
		;
		if (!$isGenerating)
		{
			return;
		}

		CopilotCallAssessmentController::getInstance()->delete($assessmentId);
	}
	// endregion

	protected static function extractPayloadFromAIResult(\Bitrix\AI\Result $result, EO_Queue $job): Dto
	{
		$json = self::extractPayloadPrettifiedData($result);
		if (empty($json))
		{
			return new CallScriptCreatorPayload([]);
		}

		return new CallScriptCreatorPayload([
			'name' => $json['name'] ?? null,
			'description' => $json['description'] ?? null,
			'newCriteria' => self::filterCriteria($json['new_criteria'] ?? []),
			'removedCriteria' => array_values(array_filter(
				(array)($json['removed_criteria'] ?? []),
				static fn ($item): bool => is_string($item) && $item !== '',
			)),
			'updatedCriteria' => self::filterCriteria($json['updated_criteria'] ?? []),
		]);
	}

	private static function filterCriteria(mixed $rows): array
	{
		if (!is_array($rows))
		{
			return [];
		}

		return array_values(array_filter(
			$rows,
			static function ($row): bool {
				if (!is_array($row))
				{
					return false;
				}

				$name = $row['name'] ?? null;
				$description = $row['description'] ?? null;

				return !empty($name) && !empty($description);
			},
		));
	}

	protected static function getJobFinishEventBuilder(): AIBaseEvent
	{
		return new GenerateCallScriptFromDialogEvent();
	}

	protected static function onAfterSuccessfulJobFinish(Result $result, ?Context $context = null): void
	{
		/** @var CallScriptCreatorPayload $payload */
		$payload = $result->getPayload();
		if (!$payload || !$result->isSuccess())
		{
			AIManager::logger()->error(
				'{date}: {class}: Error in generate call script job: {target}' . PHP_EOL,
				[
					'class' => self::class,
					'target' => $result->getTarget(),
				],
			);

			return;
		}

		$assessmentId = (int)($result->getTarget()?->getEntityId() ?? 0);
		if ($assessmentId <= 0)
		{
			AIManager::logger()->error(
				'{date}: {class}: Empty assessment target on success: {target}' . PHP_EOL,
				[
					'class' => self::class,
					'target' => $result->getTarget(),
				],
			);

			return;
		}

		$userId = $result->getUserId() ?? 0;
		$handler = new CallScriptCreatorHandler($payload, $assessmentId, $userId);
		$handler->enrichScript();
	}
}
