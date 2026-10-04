<?php

namespace Bitrix\Crm\Integration\AI\Operation;

use Bitrix\AI\Context;
use Bitrix\AI\Payload\IPayload;
use Bitrix\Crm\Dto\Dto;
use Bitrix\Crm\Format\TextHelper;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Dto\RepeatSale\ScreeningRepeatSaleItemPayload;
use Bitrix\Crm\Integration\AI\ErrorCode;
use Bitrix\Crm\Integration\AI\EventHandler;
use Bitrix\Crm\Integration\AI\Model\EO_Queue;
use Bitrix\Crm\Integration\AI\Model\QueueTable;
use Bitrix\Crm\Integration\AI\Operation\Payload\PayloadFactory;
use Bitrix\Crm\Integration\AI\Result;
use Bitrix\Crm\Integration\Analytics\Builder\AI\ScreeningRepeatSaleItemEvent;
use Bitrix\Crm\Integration\Analytics\Dictionary;
use Bitrix\Crm\ItemIdentifier;
use Bitrix\Crm\RepeatSale\DataCollector\CopilotMarkerLimitManager;
use Bitrix\Crm\RepeatSale\Schedule\Scheduler;
use Bitrix\Crm\RepeatSale\Service\Entity\RepeatSaleAiScreeningTable;
use Bitrix\Crm\RepeatSale\Service\Handler\AiScreeningOpinion;
use Bitrix\Main;
use Bitrix\Main\Web\Json;
use CCrmOwnerType;

final class ScreeningRepeatSaleItem extends AbstractOperation
{
	public const TYPE_ID = 7;
	public const CONTEXT_ID = 'screening_repeat_sale_item';

	protected const PAYLOAD_CLASS = ScreeningRepeatSaleItemPayload::class;
	protected const ENGINE_CODE = EventHandler::SETTINGS_REPEAT_SALE_ENGINE_CODE;
	private const LAUNCH_LOCK_NAME_PREFIX = 'crm_ai_screening_repeat_sale_item_';
	private const LAUNCH_LOCK_TIMEOUT = 1;

	private ?int $segmentId = null;
	private bool $isFreshCycle = false;
	private bool $isManualLaunchForDuplicateResult = true;

	/* @var ItemIdentifier[] $clientIdentifiers */
	private array $clientIdentifiers = [];
	private readonly ?int $parentJobIdForDuplicateResult;

	public function __construct(
		ItemIdentifier $target,
		?int $userId = null,
		?int $parentJobId = null,
	)
	{
		parent::__construct($target, $userId, $parentJobId);

		$this->parentJobIdForDuplicateResult = $parentJobId;
	}

	public function setSegmentId(int $segmentId): self
	{
		$this->segmentId = $segmentId;

		return $this;
	}

	public function setClientIdentifiers(array $clientIdentifiers): self
	{
		$this->clientIdentifiers = $clientIdentifiers;

		return $this;
	}

	public function setIsManualLaunch(bool $isManualLaunch): self
	{
		parent::setIsManualLaunch($isManualLaunch);
		$this->isManualLaunchForDuplicateResult = $isManualLaunch;

		return $this;
	}

	public function launch(): Result
	{
		$this->isFreshCycle = false;

		$connection = Main\Application::getConnection();
		$lockName = $this->getLaunchLockName();

		if (!$connection->lock($lockName, self::LAUNCH_LOCK_TIMEOUT))
		{
			return $this->makeRetryableLockContentionResult();
		}

		try
		{
			return parent::launch();
		}
		finally
		{
			$connection->unlock($lockName);
		}
	}

	protected function checkPreviousJobsBeforeLaunch(): Main\Result
	{
		$pool = Main\Application::getInstance()->getConnectionPool();
		$pool->useMasterOnly(true);
		try
		{
			$result = parent::checkPreviousJobsBeforeLaunch();
		}
		finally
		{
			$pool->useMasterOnly(false);
		}

		$previousJob = $result->getData()['previousJob'] ?? null;

		if (
			$previousJob instanceof EO_Queue
			&& $previousJob->requireExecutionStatus() === QueueTable::EXECUTION_STATUS_ERROR
			&& $previousJob->requireRetryCount() >= Result::MAX_RETRY_COUNT
		)
		{
			$this->isFreshCycle = true;

			$freshCycleResult = new Main\Result();
			$freshCycleResult->setData(['previousJob' => $previousJob]);

			return $freshCycleResult;
		}

		return $result;
	}

	protected function getJobUpdateFields(): array
	{
		$fields = parent::getJobUpdateFields();
		if ($this->isFreshCycle)
		{
			$fields['RETRY_COUNT'] = 0;
			$fields['CREATED_TIME'] = new Main\Type\DateTime();
			$fields['FINISHED_TIME'] = null;
		}

		return $fields;
	}

	private function getLaunchLockName(): string
	{
		return self::LAUNCH_LOCK_NAME_PREFIX
			. self::TYPE_ID
			. '_'
			. $this->target->getEntityTypeId()
			. '_'
			. $this->target->getEntityId();
	}

	private function makeRetryableLockContentionResult(): Result
	{
		$result = new Result(
			self::TYPE_ID,
			$this->target,
			$this->userId,
			parentJobId: $this->parentJobIdForDuplicateResult,
			isManualLaunch: $this->isManualLaunchForDuplicateResult,
		);
		$result->addError(
			new Main\Error(
				'Screening launch is already in progress',
				ErrorCode::OPERATION_IS_PENDING,
			),
		);

		return $result;
	}

	public static function isAccessGranted(int $userId, ItemIdentifier $target): bool
	{
		return $target->getEntityTypeId() === CCrmOwnerType::Deal;
	}

	public static function isSuitableTarget(ItemIdentifier $target): bool
	{
		return $target->getEntityTypeId() === CCrmOwnerType::Deal;
	}

	protected function getAIPayload(): Main\Result
	{
		$result = PayloadFactory::build(self::TYPE_ID, $this->userId, $this->target)
			->setEncodedMarkers(['segment_data', 'crm_data'])
			->setAdditionalData([
				'segmentId' => $this->segmentId,
				'clientIdentifiers' => $this->clientIdentifiers,
			])
			->setMarkers([])
			->getResult()
		;

		/** @var IPayload $payload */
		$payload = $result->getData()['payload'];
		if (!$this->isPayloadMarkersValid($payload->getMarkers()))
		{
			$error = ErrorCode::getInvalidPayloadMarkersForFillRepeatSaleTipsError();

			return (new Main\Result())->addError($error);
		}

		return $result;
	}

	private function isPayloadMarkersValid(array $markers): bool
	{
		if (empty($markers))
		{
			return false;
		}

		$crmData = Json::decode($markers['crm_data'] ?? '');
		if (empty($crmData))
		{
			return false;
		}

		$baseDealInfo = $crmData['base_deal_info'] ?? [];
		if (empty($baseDealInfo))
		{
			return false;
		}

		$limit = CopilotMarkerLimitManager::getInstance()->getMinSufficientAiCollectorDealFieldsLength();
		$dealFields = $baseDealInfo['deal_fields'] ?? [];
		$communicationData = $baseDealInfo['communication_data'] ?? [];

		return
			TextHelper::countCharactersInArrayFlexible($dealFields, true) > $limit
			|| TextHelper::countCharactersInArrayFlexible($communicationData) > $limit;
	}

	protected static function notifyTimelineAfterSuccessfulLaunch(Result $result): void
	{
		// operation is not used in the timeline
	}

	protected static function notifyTimelineAfterSuccessfulJobFinish(Result $result): void
	{
		// operation is not used in the timeline
	}

	protected static function extractPayloadFromAIResult(\Bitrix\AI\Result $result, EO_Queue $job): Dto
	{
		$json = self::extractPayloadPrettifiedData($result);
		if (empty($json))
		{
			return new ScreeningRepeatSaleItemPayload([]);
		}

		return new ScreeningRepeatSaleItemPayload([
			'category' => $json['category'] ?? null,
			'isRepeatSalePossible' => $json['isRepeatSalePossible'] ?? 0,
		]);
	}

	protected static function onAfterSuccessfulJobFinish(Result $result, ?Context $context = null): void
	{
		/** @var ScreeningRepeatSaleItemPayload $payload */
		$payload = $result->getPayload();

		if (!$payload || !$result->isSuccess())
		{
			AIManager::logger()->error(
				'{date}: {class}: Error in screening entity item of repeat sale: {target}' . PHP_EOL,
				[
					'class' => self::class,
					'target' => $result->getTarget(),
				],
			);

			return;
		}

		$ownerId = $result->getTarget()?->getEntityId();
		$ownerTypeId = $result->getTarget()?->getEntityTypeId();
		$segmentId = $context?->getParameters()['additionalInfo']['segmentId'] ?? null;

		$screeningItem = RepeatSaleAiScreeningTable::query()
			->setSelect(['ID'])
			->setFilter([
				'=OWNER_TYPE_ID' => $ownerTypeId,
				'=OWNER_ID' => $ownerId,
				'=SEGMENT_ID' => $segmentId,
			])
			->setOrder(['CREATED_AT' => 'DESC'])
			->setLimit(1)
			->fetchObject()
		;

		if (!$screeningItem)
		{
			AIManager::logger()->error(
				'{date}: {class}: Screening item not found: {target} {segmentId}' . PHP_EOL,
				[
					'class' => self::class,
					'target' => $result->getTarget(),
					'segmentId' => $segmentId,
				],
			);

			return;
		}

		if ($payload->isRepeatSalePossible === true)
		{
			$screeningItem->setAiOpinion(AiScreeningOpinion::isRepeatSalePossible->value);
		}
		else
		{
			$screeningItem->setAiOpinion(AiScreeningOpinion::isRepeatSaleNotPossible->value);
		}

		$screeningItem->setCategory($payload->category);
		$screeningItem->save();

		self::sendScreeningAnalyticsEvent(
			(int)$ownerId,
			(int)$ownerTypeId,
			$payload->isRepeatSalePossible === true,
			$payload->category,
			$result->isManualLaunch(),
		);

		// the children job is scheduled right after the verdict is stored: a row whose payload was
		// rejected never gets an opinion, so waiting for an empty screening queue would block
		// repeat deal creation for the whole day. Duplicates are cut off by the queue dedupe.
		if ($segmentId !== null)
		{
			Scheduler::getInstance()->addChildrenJobsToQueueIfNotExists($segmentId);
		}
	}

	protected static function notifyAboutJobError(
		Result $result,
		bool $withSyncBadges = true,
		bool $withSendAnalytics = true,
		?ItemIdentifier $target = null,
	): void
	{
		AIManager::logger()->error(
			'{date}: {class}: Error on: {target}' . PHP_EOL,
			[
				'class' => self::class,
				'target' => $result->getTarget(),
			],
		);
	}

	/**
	 * Emits the AI screening analytics event manually.
	 *
	 * The general job-finish analytics flow (@see AbstractOperation::constructJobFinishEventBuilder())
	 * builds nothing for this operation because its target is a Deal without a parent CRM activity,
	 * so the event is sent here where the verdict and category are already known.
	 */
	private static function sendScreeningAnalyticsEvent(
		int $ownerId,
		int $ownerTypeId,
		bool $isRepeatSalePossible,
		?string $category,
		bool $isManualLaunch
	): void
	{
		try
		{
			$now = new Main\Type\DateTime();

			$builder = (new ScreeningRepeatSaleItemEvent())
				->setOperationType(self::TYPE_ID)
				->setCreatedTime($now)
				->setFinishedTime($now)
				->setIsManualLaunch($isManualLaunch)
				->setActivityOwnerTypeId($ownerTypeId)
				->setActivityId($ownerId)
				->setStatus(
					$isRepeatSalePossible
						? Dictionary::STATUS_REPEAT_SALE_POSSIBLE
						: Dictionary::STATUS_REPEAT_SALE_NOT_POSSIBLE,
				)
			;

			$normalizedCategory = self::normalizeAnalyticsCategory($category);
			if ($normalizedCategory !== null)
			{
				$builder->setP3('category', $normalizedCategory);
			}

			$builder->buildEvent()->send();
			// send the same analytics only with different TOOL and CATEGORY
			$builder
				->setTool(Dictionary::TOOL_CRM)
				->setCategory(Dictionary::CATEGORY_AI_OPERATIONS)
				->buildEvent()
				->send()
			;
		}
		catch (\Throwable $e)
		{
			AIManager::logger()->error(
				'{date}: {class}: Failed to send screening analytics event: {message}' . PHP_EOL,
				[
					'class' => self::class,
					'message' => $e->getMessage(),
				],
			);
		}
	}

	private static function normalizeAnalyticsCategory(?string $category): ?string
	{
		if ($category === null || trim($category) === '')
		{
			return null;
		}

		return str_replace(['_', ' '], '-', mb_strtolower(trim($category)));
	}

	protected static function getJobFinishEventBuilder(): ScreeningRepeatSaleItemEvent
	{
		return new ScreeningRepeatSaleItemEvent();
	}

	protected function getContextAdditionalInfo(): array
	{
		$additionalInfo = parent::getContextAdditionalInfo();

		$additionalInfo['segmentId'] = $this->segmentId;

		return $additionalInfo;
	}
}
