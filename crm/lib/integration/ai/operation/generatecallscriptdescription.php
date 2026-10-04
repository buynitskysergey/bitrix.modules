<?php

namespace Bitrix\Crm\Integration\AI\Operation;

use Bitrix\AI\Context;
use Bitrix\Crm\Copilot\CallScriptEditReview\EditReviewRepository;
use Bitrix\Crm\Copilot\CallScriptMaintenance\Dispatcher;
use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Dto\Scoring\GenerateCallScriptDescriptionPayload;
use Bitrix\Crm\Integration\AI\ErrorCode;
use Bitrix\Crm\Integration\AI\EventHandler;
use Bitrix\Crm\Integration\AI\Model\EO_Queue;
use Bitrix\Crm\Integration\AI\Operation\Handler\GenerateCallScriptDescriptionHandler;
use Bitrix\Crm\Integration\AI\Operation\Payload\PayloadFactory;
use Bitrix\Crm\Integration\AI\Operation\Payload\RequiredInputInterface;
use Bitrix\Crm\Integration\AI\Result;
use Bitrix\Crm\Integration\Analytics\Builder\AI\AIBaseEvent;
use Bitrix\Crm\Integration\Analytics\Builder\AI\GenerateCallScriptDescriptionEvent;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Container;
use Bitrix\Main;
use CCrmOwnerType;

final class GenerateCallScriptDescription extends AbstractOperation
{
	public const TYPE_ID = 16;
	public const CONTEXT_ID = 'generate_call_script_description';

	protected const PAYLOAD_CLASS = GenerateCallScriptDescriptionPayload::class;
	protected const ENGINE_CODE = EventHandler::SETTINGS_CALL_ASSESSMENT_ENGINE_CODE;

	private const ANSWER_FIELDS = ['name', 'description'];

	private ?Main\Result $aiPayloadResultCache = null;

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

	/**
	 * The prompt has no fallback for a script without criteria, client types or call direction:
	 * such a launch is refused before the job reaches the queue.
	 */
	public function checkRequiredInput(): Main\Result
	{
		$result = new Main\Result();

		$payloadResult = $this->getAIPayload();
		if (!$payloadResult->isSuccess())
		{
			$result->addErrors($payloadResult->getErrors());
		}

		return $result;
	}

	protected static function checkPreviousJobs(ItemIdentifier $target, int $parentId): Main\Result
	{
		return new Main\Result();
	}

	protected function getAIPayload(): Main\Result
	{
		if ($this->aiPayloadResultCache === null)
		{
			$this->aiPayloadResultCache = $this->buildAIPayloadResult();
		}

		return $this->aiPayloadResultCache;
	}

	private function buildAIPayloadResult(): Main\Result
	{
		$payload = PayloadFactory::build(self::TYPE_ID, $this->userId, $this->target)->setMarkers([]);

		if (!$payload instanceof RequiredInputInterface)
		{
			AIManager::logger()->error(
				'{date}: {class}: payload {payload} of target {target} cannot check the required input' . PHP_EOL,
				[
					'class' => self::class,
					'payload' => $payload::class,
					'target' => $this->target,
				],
			);

			return (new Main\Result())->addError(ErrorCode::getInvalidPayloadError());
		}

		if (!$payload->hasRequiredInput())
		{
			AIManager::logger()->warning(
				'{date}: {class}: call script {target} has no {missingInput} - launch refused' . PHP_EOL,
				[
					'class' => self::class,
					'target' => $this->target,
					'missingInput' => implode(', ', $payload->getMissingRequiredInput()),
				],
			);

			return (new Main\Result())->addError(ErrorCode::getInvalidPayloadError());
		}

		return $payload->getResult();
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

		$assessmentId = $result->getTarget()?->getEntityId() ?? 0;
		$jobId = $result->getJobId() ?? 0;
		if ($assessmentId > 0 && $jobId > 0)
		{
			EditReviewRepository::getInstance()->clearIfMatches($assessmentId, $jobId);
		}

		Dispatcher::getInstance()->pingAgent();
	}
	// endregion

	protected static function extractPayloadFromAIResult(\Bitrix\AI\Result $result, EO_Queue $job): Dto
	{
		$json = self::extractPayloadPrettifiedData($result);

		return new GenerateCallScriptDescriptionPayload(self::collectAnswerFields($json));
	}

	/**
	 * Only the fields the model really answered with reach the DTO: a missing one leaves the property
	 * of the DTO untouched, while passing it as null would fail the validation of the whole job.
	 */
	private static function collectAnswerFields(array $json): array
	{
		$fields = [];

		foreach (self::ANSWER_FIELDS as $field)
		{
			$value = $json[$field] ?? null;
			if (!is_string($value))
			{
				continue;
			}

			$value = self::stripUnresolvedMarkers($value);
			if ($value !== null)
			{
				$fields[$field] = $value;
			}
		}

		return $fields;
	}

	protected static function getJobFinishEventBuilder(): AIBaseEvent
	{
		return new GenerateCallScriptDescriptionEvent();
	}

	protected static function onAfterSuccessfulJobFinish(Result $result, ?Context $context = null): void
	{
		/** @var GenerateCallScriptDescriptionPayload $payload */
		$payload = $result->getPayload();
		if (!$payload || !$result->isSuccess())
		{
			AIManager::logger()->error(
				'{date}: {class}: Error in generate call script description job: {target}' . PHP_EOL,
				[
					'class' => self::class,
					'target' => $result->getTarget(),
				],
			);

			return;
		}

		$assessmentId = $result->getTarget()?->getEntityId() ?? 0;
		$jobId = $result->getJobId() ?? 0;

		(new GenerateCallScriptDescriptionHandler($assessmentId, $payload))->applyDescriptionByJob($jobId);
	}
}
