<?php

namespace Bitrix\Crm\RepeatSale\Segment;

use Bitrix\Crm\Feature;
use Bitrix\Crm\RepeatSale\AvailabilityChecker;
use Bitrix\Crm\RepeatSale\Logger;
use Bitrix\Crm\RepeatSale\Segment\Controller\RepeatSaleSegmentController;
use Bitrix\Crm\Service\Container;

/**
 * Domain service for the positive subscription transition of RepeatSale AI segments.
 *
 * When a subscription returns (auto-disabled segments exist) the service restores them; when a
 * subscription appears for the first time (no memory, marker not set) it enables all admissible
 * AI scenarios once and sets the marker. An ordinary renewal on an already configured portal is
 * a no-op so the manual choice is never overridden. Everything happens silently (no user-facing
 * messages), mirroring the disable path of the scheduler.
 *
 * The subscription "seen" marker is the only reliable signal at event-time: rest:onSubscriptionRenew
 * carries no payload and, by the time it fires, the subscription final date is already set, so
 * Marketplace\Client is not consulted to distinguish the transition here.
 *
 * @see \Bitrix\Crm\RepeatSale\Schedule\Scheduler::tryDisableSegment() the symmetric disable path
 */
final class SubscriptionSegmentService
{
	private AvailabilityChecker $availabilityChecker;
	private RepeatSaleSegmentController $segmentController;
	private SegmentManager $segmentManager;
	private SubscriptionSeenMarker $seenMarker;
	private Logger $logger;

	public function __construct(
		?AvailabilityChecker $availabilityChecker = null,
		?RepeatSaleSegmentController $segmentController = null,
		?SegmentManager $segmentManager = null,
		?SubscriptionSeenMarker $seenMarker = null,
	)
	{
		$this->availabilityChecker = $availabilityChecker
			?? Container::getInstance()->getRepeatSaleAvailabilityChecker();
		$this->segmentController = $segmentController ?? RepeatSaleSegmentController::getInstance();
		$this->segmentManager = $segmentManager ?? new SegmentManager();
		$this->seenMarker = $seenMarker ?? new SubscriptionSeenMarker();
		$this->logger = new Logger();
	}

	/**
	 * Entry point of the positive subscription transition.
	 */
	public function onPositiveTransition(): void
	{
		// Gate on stable availability only: isAvailable() also folds in transient states
		// (segment initialization in progress, item limit exceeded) that would make a renewal
		// arriving in that window skip restore permanently, with no retry. The scheduler still
		// re-disables per run if AI turns out unavailable, so isEnabled() is the safe signal.
		if (!$this->availabilityChecker->isEnabled())
		{
			return;
		}

		$autoDisabledSegments = $this->getAutoDisabledSegments();
		if (!empty($autoDisabledSegments))
		{
			$this->restoreAutoDisabledSegments($autoDisabledSegments);
		}
		elseif (!$this->seenMarker->isSet())
		{
			$this->enableAllAdmissibleAiSegments();
		}

		// Always mark the subscription as seen, including the restore branch: otherwise a later
		// renewal with no auto-disabled segments left would misfire the first-appearance path
		// and re-enable all AI scenarios against a manual choice.
		$this->seenMarker->set();
	}

	/**
	 * Restores every auto-disabled segment whose scenario feature is enabled: turns it back on and
	 * clears the auto-disable memory. Only IS_AUTO_DISABLED = Y rows are touched, so manual disables
	 * are left intact. The child ai_approve carries the flag from the disable path, so restoring
	 * "all auto-disabled" restores it in sync with its ai_screening parent.
	 *
	 * @param SegmentItem[] $segments
	 */
	private function restoreAutoDisabledSegments(array $segments): void
	{
		$restoredCodes = [];

		foreach ($segments as $segment)
		{
			$code = $segment->getCode();
			if ($code === null || !$this->isFeatureEnabledForCode($code))
			{
				continue;
			}

			$segment
				->setIsEnabled(true)
				->setIsAutoDisabled(false)
			;

			$this->segmentController->update($segment->getId(), $segment);

			$restoredCodes[] = $code;
		}

		if (!empty($restoredCodes))
		{
			$this->logger->info('Restored auto-disabled segments on subscription return', $restoredCodes);
		}
	}

	/**
	 * First-appearance path: enable all AI scenarios (ai_screening, ai_approve, remaining) allowed by
	 * their scenario features. enableSegmentsByCodes only flips IS_ENABLED; there is no auto-disable
	 * memory to clear here, so the low-level contract is enough.
	 */
	private function enableAllAdmissibleAiSegments(): void
	{
		$codes = $this->filterByFeature([
			SegmentCode::AI_SCREENING->value,
			SegmentCode::AI_APPROVE->value,
			SegmentCode::REMAINING->value,
		]);

		if (empty($codes))
		{
			return;
		}

		$this->segmentManager->enableSegmentsByCodes($codes);

		$this->logger->info('Enabled AI segments on first subscription appearance', $codes);
	}

	/**
	 * @return SegmentItem[]
	 */
	private function getAutoDisabledSegments(): array
	{
		$segments = $this->segmentController->getList([
			'select' => ['*', 'ASSIGNMENT_USERS.USER_ID'],
			'filter' => [
				'=IS_AUTO_DISABLED' => 'Y',
			],
			'limit' => 0, // all matching segments
		]);

		$result = [];
		foreach ($segments as $segment)
		{
			$result[] = SegmentItem::createFromEntity($segment);
		}

		return $result;
	}

	/**
	 * @param string[] $codes
	 * @return string[]
	 */
	private function filterByFeature(array $codes): array
	{
		return array_values(array_filter(
			$codes,
			fn (string $code): bool => $this->isFeatureEnabledForCode($code),
		));
	}

	private function isFeatureEnabledForCode(string $code): bool
	{
		if (!SegmentCode::isNeedAiModule($code))
		{
			return false;
		}

		if ($code === SegmentCode::REMAINING->value)
		{
			return Feature::enabled(Feature\RepeatSaleRemainingSegment::class);
		}

		return Feature::enabled(Feature\RepeatSaleAiSegment::class);
	}
}
