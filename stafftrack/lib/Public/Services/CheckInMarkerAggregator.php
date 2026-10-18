<?php

namespace Bitrix\StaffTrack\Public\Services;

use Bitrix\StaffTrack\Internal\Entity\CheckInType;

class CheckInMarkerAggregator
{
	public const KIND_MANUAL = 'manual';
	public const KIND_STAY = 'stay';
	public const KIND_MERGED = 'merged';

	private const KIND_TECHNICAL = 'technical';

	private const DESCRIPTION_ENTER = 'ENTER';
	private const DESCRIPTION_LEAVE = 'LEAVE';

	private const MERGE_RADIUS_METERS = 30;
	private const STOP_RADIUS_METERS = 50;
	// Silence splits a visit, distance in time alone does not: the module calls a gap a break in a day only
	// when the track also moved away (CheckInProvider::GAP_THRESHOLD_SEC plus GAP_DISTANCE_METERS).
	// isSameStop() holds that place half, so this is how long the track may confirm nothing before a manual
	// check-in and a stay at the same place count as two visits.
	private const MERGE_UNSEEN_GAP_MAX_SEC = 1800;
	private const EARTH_RADIUS_METERS = 6_371_000;

	/**
	 * @param array<int, array{id: int, entityType: int, description: string, latitude: ?float, longitude: ?float, address: string, timestamp: int, userTimezone: int}> $events
	 * @return array<int, array{kind: string, event: array, duration: ?int, auto: ?array, timestamp: int, endTimestamp: int}>
	 */
	public function buildMarkers(array $events, ?int $dayCloseTimestamp = null): array
	{
		$items = $this->collapseAutoCheckIns($events, $dayCloseTimestamp);
		$merged = $this->mergeManualWithStays($items);

		return $this->filterTechnical($merged);
	}

	/**
	 * @param array<int, array> $events
	 * @return array<int, array>
	 */
	private function collapseAutoCheckIns(array $events, ?int $dayCloseTimestamp): array
	{
		$items = [];
		$openEnter = null;

		foreach ($events as $event)
		{
			// A manual check-in never closes an open stay: the real LEAVE stays the visit boundary,
			// the same open-enter model as DailyStatsUpdater::hasOpenEnter().
			if ($event['entityType'] === CheckInType::MANUAL->value)
			{
				$items[] = $this->makeManual($event);
				continue;
			}

			if ($event['description'] === self::DESCRIPTION_ENTER)
			{
				if ($openEnter !== null)
				{
					$items[] = $this->makeStay($openEnter, $event['timestamp']);
				}

				$openEnter = $event;
				continue;
			}

			if ($event['description'] === self::DESCRIPTION_LEAVE)
			{
				if ($openEnter !== null)
				{
					$items[] = $this->makeStay($openEnter, $event['timestamp']);
					$openEnter = null;
				}
				else
				{
					$items[] = $this->makeTechnical($event);
				}

				continue;
			}

			$items[] = $this->makeTechnical($event);
		}

		if ($openEnter !== null)
		{
			$items[] = $this->makeStay($openEnter, $this->resolveOpenEnterClose($openEnter, $dayCloseTimestamp));
		}

		return $items;
	}

	private function resolveOpenEnterClose(array $openEnter, ?int $dayCloseTimestamp): int
	{
		if ($dayCloseTimestamp !== null && $dayCloseTimestamp >= $openEnter['timestamp'])
		{
			return $dayCloseTimestamp;
		}

		return time();
	}

	private function makeStay(array $enter, int $closeTimestamp): array
	{
		return [
			'kind' => self::KIND_STAY,
			'event' => $enter,
			'duration' => $closeTimestamp - $enter['timestamp'],
			'auto' => null,
			'timestamp' => $enter['timestamp'],
			'endTimestamp' => $closeTimestamp,
		];
	}

	private function makeManual(array $event): array
	{
		return [
			'kind' => self::KIND_MANUAL,
			'event' => $event,
			'duration' => null,
			'auto' => null,
			'timestamp' => $event['timestamp'],
			'endTimestamp' => $event['timestamp'],
		];
	}

	private function makeTechnical(array $event): array
	{
		return [
			'kind' => self::KIND_TECHNICAL,
			'event' => $event,
			'duration' => null,
			'auto' => null,
			'timestamp' => $event['timestamp'],
			'endTimestamp' => $event['timestamp'],
		];
	}

	/**
	 * @param array<int, array> $items
	 * @return array<int, array>
	 */
	private function mergeManualWithStays(array $items): array
	{
		$technical = array_values(array_filter($items, static fn (array $it): bool => $it['kind'] === self::KIND_TECHNICAL));

		$manualKeys = [];
		$stayKeys = [];
		foreach ($items as $key => $item)
		{
			if ($item['kind'] === self::KIND_MANUAL)
			{
				$manualKeys[] = $key;
			}
			elseif ($item['kind'] === self::KIND_STAY)
			{
				$stayKeys[] = $key;
			}
		}

		usort($manualKeys, static fn (int $a, int $b): int => $items[$a]['timestamp'] <=> $items[$b]['timestamp']);

		$consumedStay = [];
		$mergedManual = [];

		foreach ($manualKeys as $manualKey)
		{
			$manual = $items[$manualKey];

			foreach ($this->sortStayKeysByGap($manual, $stayKeys, $consumedStay, $items) as $stayKey)
			{
				$stay = $items[$stayKey];
				$betweenPoints = $this->collectBetweenPoints($manual, $stay, $technical);

				if (!$this->isSameStop($manual, $stay, $betweenPoints))
				{
					continue;
				}

				if (!$this->isPresenceContinuous($manual, $stay, $betweenPoints))
				{
					continue;
				}

				$consumedStay[$stayKey] = true;
				$mergedManual[$manualKey] = $stayKey;
				break;
			}
		}

		$result = [];
		foreach ($items as $key => $item)
		{
			if ($item['kind'] === self::KIND_STAY && isset($consumedStay[$key]))
			{
				continue;
			}

			if ($item['kind'] === self::KIND_MANUAL && isset($mergedManual[$key]))
			{
				$result[] = $this->makeMerged($item, $items[$mergedManual[$key]]);
				continue;
			}

			$result[] = $item;
		}

		return $result;
	}

	/**
	 * Candidates are ordered by the time gap, so the closest stay is still preferred, but a stay
	 * rejected by isSameStop() no longer blocks the remaining ones.
	 *
	 * @param array<int, int> $stayKeys
	 * @param array<int, bool> $consumedStay
	 * @param array<int, array> $items
	 * @return array<int, int>
	 */
	private function sortStayKeysByGap(array $manual, array $stayKeys, array $consumedStay, array $items): array
	{
		$candidates = [];
		foreach ($stayKeys as $stayKey)
		{
			if (!isset($consumedStay[$stayKey]))
			{
				$candidates[] = $stayKey;
			}
		}

		usort(
			$candidates,
			fn (int $a, int $b): int => $this->timeGap($manual, $items[$a]) <=> $this->timeGap($manual, $items[$b]),
		);

		return $candidates;
	}

	private function timeGap(array $manual, array $stay): int
	{
		[$low, $high] = $this->gapInterval($manual, $stay);

		return max(0, $high - $low);
	}

	/**
	 * The span the manual check-in and the stay do not share. A manual check-in taken inside the stay
	 * gives an empty span, so the bounds come back in reverse order.
	 *
	 * @return array{0: int, 1: int}
	 */
	private function gapInterval(array $manual, array $stay): array
	{
		if ($manual['timestamp'] <= $stay['timestamp'])
		{
			return [$manual['timestamp'], $stay['timestamp']];
		}

		return [$stay['endTimestamp'], $manual['timestamp']];
	}

	/**
	 * @param array<int, array> $technical
	 * @return array<int, array>
	 */
	private function collectBetweenPoints(array $manual, array $stay, array $technical): array
	{
		[$low, $high] = $this->gapInterval($manual, $stay);

		$points = [];
		foreach ($technical as $item)
		{
			$timestamp = $item['event']['timestamp'];
			if ($timestamp > $low && $timestamp < $high)
			{
				$points[] = $item['event'];
			}
		}

		return $points;
	}

	/**
	 * The time half of the merge rule: the stop stays confirmed while the track keeps reporting it, and
	 * every point in between is already inside STOP_RADIUS_METERS by isSameStop(). A silence longer than
	 * MERGE_UNSEEN_GAP_MAX_SEC leaves room for a visit the day did not record, so the merge is dropped.
	 *
	 * @param array<int, array> $betweenPoints
	 */
	private function isPresenceContinuous(array $manual, array $stay, array $betweenPoints): bool
	{
		[$low, $high] = $this->gapInterval($manual, $stay);
		if ($high - $low <= self::MERGE_UNSEEN_GAP_MAX_SEC)
		{
			return true;
		}

		$lastConfirmed = $low;
		foreach ($betweenPoints as $point)
		{
			if ($point['latitude'] === null || $point['longitude'] === null)
			{
				continue;
			}

			if ($point['timestamp'] - $lastConfirmed > self::MERGE_UNSEEN_GAP_MAX_SEC)
			{
				return false;
			}

			$lastConfirmed = $point['timestamp'];
		}

		return $high - $lastConfirmed <= self::MERGE_UNSEEN_GAP_MAX_SEC;
	}

	/**
	 * @param array<int, array> $betweenPoints
	 */
	private function isSameStop(array $manual, array $stay, array $betweenPoints): bool
	{
		if (!$this->isNear($manual['event'], $stay['event']))
		{
			return false;
		}

		$center = $this->stopCenter($manual['event'], $stay['event']);
		if ($center === null)
		{
			return true;
		}

		foreach ($betweenPoints as $point)
		{
			if ($point['latitude'] === null || $point['longitude'] === null)
			{
				continue;
			}

			$distance = $this->flatDistanceInMeters($center[0], $center[1], $point['latitude'], $point['longitude']);
			if ($distance > self::STOP_RADIUS_METERS)
			{
				return false;
			}
		}

		return true;
	}

	private function isNear(array $manual, array $stay): bool
	{
		$hasCoords =
			$manual['latitude'] !== null && $manual['longitude'] !== null
			&& $stay['latitude'] !== null && $stay['longitude'] !== null;

		if ($hasCoords)
		{
			$distance = $this->flatDistanceInMeters($manual['latitude'], $manual['longitude'], $stay['latitude'], $stay['longitude']);
			if ($distance <= self::MERGE_RADIUS_METERS)
			{
				return true;
			}
		}

		return $manual['address'] !== '' && $manual['address'] === $stay['address'];
	}

	/**
	 * @return ?array{0: float, 1: float}
	 */
	private function stopCenter(array $manual, array $stay): ?array
	{
		if ($stay['latitude'] !== null && $stay['longitude'] !== null)
		{
			return [$stay['latitude'], $stay['longitude']];
		}

		if ($manual['latitude'] !== null && $manual['longitude'] !== null)
		{
			return [$manual['latitude'], $manual['longitude']];
		}

		return null;
	}

	private function makeMerged(array $manual, array $stay): array
	{
		$auto = $stay['event'];

		return [
			'kind' => self::KIND_MERGED,
			'event' => $manual['event'],
			'duration' => $stay['duration'],
			'auto' => [
				'id' => $auto['id'],
				'timestamp' => $auto['timestamp'],
				'latitude' => $auto['latitude'],
				'longitude' => $auto['longitude'],
				'address' => $auto['address'],
			],
			'timestamp' => min($manual['timestamp'], $stay['timestamp']),
			'endTimestamp' => max($manual['timestamp'], $stay['endTimestamp']),
		];
	}

	/**
	 * @param array<int, array> $items
	 * @return array<int, array{kind: string, event: array, duration: ?int, auto: ?array, timestamp: int, endTimestamp: int}>
	 */
	private function filterTechnical(array $items): array
	{
		$markers = array_values(array_filter($items, static fn (array $it): bool => $it['kind'] !== self::KIND_TECHNICAL));

		usort(
			$markers,
			static fn (array $a, array $b): int => $a['timestamp'] <=> $b['timestamp'] ?: $a['event']['id'] <=> $b['event']['id'],
		);

		return array_map(
			static fn (array $it): array => [
				'kind' => $it['kind'],
				'event' => $it['event'],
				'duration' => $it['duration'],
				'auto' => $it['auto'],
				'timestamp' => $it['timestamp'],
				'endTimestamp' => $it['endTimestamp'],
			],
			$markers,
		);
	}

	private function flatDistanceInMeters(float $lat1, float $lon1, float $lat2, float $lon2): float
	{
		$avgLat = deg2rad(($lat1 + $lat2) / 2);
		$dx = deg2rad($lon2 - $lon1) * cos($avgLat);
		$dy = deg2rad($lat2 - $lat1);

		return self::EARTH_RADIUS_METERS * sqrt($dx * $dx + $dy * $dy);
	}
}
