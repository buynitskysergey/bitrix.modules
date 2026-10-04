<?php

namespace Bitrix\Crm\Integration\AI\Operation;

use Bitrix\AI\Context;
use Bitrix\AI\Engine;
use Bitrix\AI\Quality;
use Bitrix\Crm\Activity\Provider\Call;
use Bitrix\Crm\Activity\Provider\Email;
use Bitrix\Crm\Activity\Provider\OpenLine;
use Bitrix\Crm\Badge;
use Bitrix\Crm\Copilot\AiCallSummary\Controller\AiCallSummaryController;
use Bitrix\Crm\Copilot\AiCallSummary\Entity\AiCallSummaryItem;
use Bitrix\Crm\Copilot\Pipeline\StepContext;
use Bitrix\Crm\Copilot\Pipeline\TargetResolver;
use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Integration\AI\Config;
use Bitrix\Crm\Integration\AI\Dto\SummarizeCallTranscriptionPayload;
use Bitrix\Crm\Integration\AI\Model\EO_Queue;
use Bitrix\Crm\Integration\AI\Operation\Payload\PayloadFactory;
use Bitrix\Crm\Integration\AI\Result;
use Bitrix\Crm\Integration\Analytics\Builder\AI\AIBaseEvent;
use Bitrix\Crm\Integration\Analytics\Builder\AI\SummaryEvent;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Timeline\AI\Controller;
use Bitrix\Main;
use CCrmActivity;
use CCrmOwnerType;

final class SummarizeCallTranscription extends AbstractOperation
{
	public const TYPE_ID = 2;
	public const CONTEXT_ID = 'summarize_call_transcription';

	public const SUPPORTED_TARGET_ENTITY_TYPE_IDS = [
		CCrmOwnerType::Activity,
	];
	public const SUPPORTED_ACTIVITY_PROVIDER_IDS = [
		Call::ACTIVITY_PROVIDER_ID,
		OpenLine::ACTIVITY_PROVIDER_ID,
		Email::ACTIVITY_PROVIDER_ID,
	];

	protected const PAYLOAD_CLASS = SummarizeCallTranscriptionPayload::class;

	public function __construct(
		ItemIdentifier $target,
		private readonly string $transcription,
		?int $userId = null,
		?int $parentJobId = null,
	)
	{
		parent::__construct($target, $userId, $parentJobId);
	}

	public static function isAccessGranted(int $userId, ItemIdentifier $target): bool
	{
		return parent::isAccessGranted($userId, $target)
			&& CCrmActivity::CheckItemUpdatePermission(
				['ID' => $target->getEntityId()],
				Container::getInstance()->getUserPermissions($userId)->getCrmPermissions(),
			)
		;
	}

	public static function canProceedToNextStep(Result $result, StepContext $context): bool
	{
		if (!$result->isSuccess())
		{
			return false;
		}

		$payload = $result->getPayload();

		return $payload instanceof SummarizeCallTranscriptionPayload && !empty($payload->summary);
	}

	public static function shouldRelaunch(Result $existingResult, StepContext $context): bool
	{
		if ($context->getActivityId() <= 0)
		{
			return false;
		}

		if ($context->getActivityProvider() === Email::getId())
		{
			$ownerTypeId = (int)$context->getExtra('targetOwnerTypeId');
			$ownerId = (int)$context->getExtra('targetOwnerId');

			return $ownerTypeId > 0 && $ownerId > 0
				&& Email::isCopilotRepeatProcessingAvailable($context->getActivityId(), $ownerTypeId, $ownerId);
		}

		if (
			!$context->isManualLaunch()
			|| $context->getActivityProvider() !== OpenLine::getId()
		)
		{
			return false;
		}

		// Extras branch is a testing seam: unit tests inject pre-computed `messagesForCopilot`
		// and `lastMessagesVolumeForCopilot` via StepContext to avoid activity/broker fixtures.
		// Production callers do not set these — the fallback below reads the same data from the activity.
		$messages = $context->getExtra('messagesForCopilot');
		$lastMessagesVolume = $context->getExtra('lastMessagesVolumeForCopilot');
		if (is_string($messages) && is_numeric($lastMessagesVolume))
		{
			return mb_strlen($messages, 'UTF-8') >= (int)$lastMessagesVolume + OpenLine::CHAT_MESSAGE_COPILOT_PROCESSING_LIMIT;
		}

		// The summary feeds FillFields, so gate its relaunch on the same per-entity baseline as the fill
		// button (keyed by the fill target). Otherwise Analyze/Summarize advancing the chat-global baseline
		// would suppress the relaunch and fill would run on a stale summary. See fill-fields-any-entity.
		$fillTarget = in_array($context->getScenarioName(), [Scenario::FILL_FIELDS_SCENARIO, Scenario::FULL_SCENARIO], true)
			? $context->resolveFillTarget(new TargetResolver())
			: null
		;

		return OpenLine::isCopilotProcessingAvailable(
			$context->getActivityId(),
			is_string($messages) ? $messages : '',
			true,
			$fillTarget,
		);
	}

	public static function isSuitableTarget(ItemIdentifier $target): bool
	{
		if ($target->getEntityTypeId() === CCrmOwnerType::Activity)
		{
			$activity = Container::getInstance()->getActivityBroker()->getById($target->getEntityId());
			if (
				$activity
				&& isset($activity['PROVIDER_ID'])
				&& in_array($activity['PROVIDER_ID'], self::SUPPORTED_ACTIVITY_PROVIDER_IDS, true)
			)
			{
				return true;
			}
		}

		return false;
	}

	protected static function checkPreviousJobs(ItemIdentifier $target, int $parentId): Main\Result
	{
		$activity = Container::getInstance()->getActivityBroker()->getById($target->getEntityId());
		$providerId = $activity['PROVIDER_ID'] ?? null;
		if ($providerId === OpenLine::getId() || $providerId === Email::getId())
		{
			return parent::checkPreviousJobsAllowingSuccessfulRelaunch($target, $parentId);
		}

		return parent::checkPreviousJobs($target, $parentId);
	}

	protected function getAIPayload(): Main\Result
	{
		return PayloadFactory::build(self::TYPE_ID, $this->userId, $this->target)
			->setMarkers([
				'original_message' => $this->transcription,
			])->getResult()
		;
	}

	protected function getContextLanguageId(): string
	{
		$itemIdentifier = $this->targetResolver->findTarget($this->target->getEntityId());
		if ($itemIdentifier)
		{
			return Config::getLanguageId(
				$this->userId,
				$itemIdentifier->getEntityTypeId(),
				$itemIdentifier->getCategoryId()
			);
		}

		return parent::getContextLanguageId();
	}

	protected static function notifyTimelineAfterSuccessfulLaunch(Result $result): void
	{
		$activityId = $result->getTarget()?->getEntityId();
		$nextTarget = (new TargetResolver())->findTarget($activityId);
		if ($nextTarget)
		{
			$activity = Container::getInstance()->getActivityBroker()->getById($activityId);
			if (($activity['PROVIDER_ID'] ?? null) !== Email::getId())
			{
				OpenLine::saveLastMessagesVolumeForCopilot($activityId);
			}
			self::notifyTimelinesAboutActivityUpdate($activityId, true);
		}
	}

	protected static function notifyTimelineAfterSuccessfulJobFinish(Result $result): void {}

	protected static function notifyAboutJobError(
		Result $result,
		bool $withSyncBadges = true,
		bool $withSendAnalytics = true,
		?ItemIdentifier $target = null
	): void
	{
		$activityId = $result->getTarget()?->getEntityId();
		// Prefer the clicked entity carried across the async boundary (ERR-002/AC-030). The auto path and
		// legacy single-target callers pass no target and keep resolving the priority Deal/Lead via findTarget.
		// Gating on the resolved target (not findTarget alone) lets a contact-only manual launch get the badge.
		$badgeTarget = $target ?? (new TargetResolver())->findTarget($activityId);
		if ($badgeTarget)
		{
			if ($withSyncBadges)
			{
				Controller::getInstance()->onLaunchError(
					$badgeTarget,
					$activityId,
					[
						'OPERATION_TYPE_ID' => self::TYPE_ID,
						'ENGINE_ID' => self::$engineId,
						'ERRORS' => array_unique($result->getErrorMessages()),
					],
					$result->getUserId(),
				);

				self::syncBadges($activityId, Badge\Type\AiCallFieldsFillingResult::ERROR_PROCESS_VALUE, $badgeTarget);
			}

			self::notifyTimelinesAboutActivityUpdate($activityId);

			if ($withSendAnalytics)
			{
				self::sendCallParsingAnalyticsEvent(
					$result,
					$activityId
				);
			}
		}
	}

	protected static function onAfterSuccessfulJobFinish(Result $result, ?Context $context = null): void
	{
		$activityId = $result->getTarget()?->getEntityId();
		if (!$activityId)
		{
			return;
		}

		$activity = Container::getInstance()->getActivityBroker()->getById($activityId);
		$isEmailActivity = (($activity['PROVIDER_ID'] ?? null) === Email::getId());
		if ($isEmailActivity)
		{
			$additionalInfo = $context?->getParameters()['additionalInfo'] ?? [];
			$targetOwnerTypeId = is_array($additionalInfo) ? (int)($additionalInfo['targetOwnerTypeId'] ?? 0) : 0;
			$targetOwnerId = is_array($additionalInfo) ? (int)($additionalInfo['targetOwnerId'] ?? 0) : 0;

			Email::saveCopilotContentFingerprint($activityId, $targetOwnerTypeId, $targetOwnerId);
		}

		self::notifyTimelinesAboutActivityUpdate($activityId);

		if (!$result->isSuccess() || $isEmailActivity)
		{
			return;
		}

		/** @var SummarizeCallTranscriptionPayload|null $payload */
		$payload = $result->getPayload();
		$data = $payload?->data;
		if ($data === null)
		{
			return;
		}

		$controller = AiCallSummaryController::getInstance();
		$item = AiCallSummaryItem::createFromEntityFields([
			'ACTIVITY_ID' => $activityId,
			'JOB_ID' => (int)$result->getJobId(),
			'THEME' => $data->theme,
			'PRODUCT' => $data->product,
			'INTENT' => $data->intent,
		]);

		$existing = $controller->getByActivityId($activityId);
		if ($existing !== null && $existing->getId() !== null)
		{
			$controller->update($existing->getId(), $item);
		}
		else
		{
			$controller->add($item);
		}
	}

	protected static function extractPayloadFromAIResult(\Bitrix\AI\Result $result, EO_Queue $job): Dto
	{
		$json = self::extractPayloadPrettifiedData($result);

		// Version-tolerant parsing: the `summarize_transcript` prompt is rolled out independently
		// of the speech-analytics feature, so a job may return either the new JSON payload
		// (`{summary, data}`) or the legacy plain-text summary. We can't rely on rollout ordering,
		// so we sniff the shape. A JSON object carrying a `summary` key is the new format; anything
		// else is treated as the legacy plain-text summary.
		if (array_key_exists('summary', $json))
		{
			return new SummarizeCallTranscriptionPayload([
				'summary' => $json['summary'] ?? '',
				'data' => $json['data'] ?? null,
			]);
		}

		// Legacy plain-text response: the whole prettified text is the summary and there is no
		// structured data. Keeping `data` null makes `onAfterSuccessfulJobFinish` skip the
		// AiCallSummaryItem write, so an old prompt never produces empty summary rows.
		return new SummarizeCallTranscriptionPayload([
			'summary' => self::extractPayloadString($result->getPrettifiedData()) ?? '',
			'data' => null,
		]);
	}

	protected static function getJobFinishEventBuilder(): AIBaseEvent
	{
		return new SummaryEvent();
	}

	protected static function setQuality(Engine $engine): void
	{
		if (isset(Quality::QUALITIES['summarize']) && method_exists($engine->getIEngine(), 'setQuality'))
		{
			$engine->getIEngine()->setQuality(Quality::QUALITIES['summarize']);
		}
	}
}
