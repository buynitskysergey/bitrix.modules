<?php

namespace Bitrix\StaffTrack\Public\Command\CheckIn;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Text\Emoji;
use Bitrix\Main\Type\DateTime;
use Bitrix\StaffTrack\Feature;
use Bitrix\StaffTrack\Public\Command\CheckInDto;
use Bitrix\StaffTrack\Internal\Repository\Mapper\CheckInMapper;
use Bitrix\StaffTrack\Internal\Integration\Disk\FileResolver;
use Bitrix\StaffTrack\Internal\Integration\Pull;
use Bitrix\StaffTrack\Public\Provider\DepartmentProvider;
use Bitrix\StaffTrack\Service\UserService;
abstract class AbstractCommand
{
	/**
	 * How far back a client-supplied creation time may reach: two days. The client outbox drops a
	 * point older than a day, so the window follows it with a margin - a point queued just under the
	 * client limit may reach the server later, and the two clocks are not the same clock.
	 */
	private const MAX_POINT_AGE_SEC = 172800;

	/**
	 * A creation time ahead of the server by more than this is taken as the server time instead of
	 * being refused: a device clock running fast would otherwise never get its queue accepted.
	 */
	private const FUTURE_TOLERANCE_SEC = 300;

	/**
	 * The point that closes the route of its day; the client keeps it in the outbox at any age, so
	 * the age window does not apply to it (check-in-v3/auto-check-in/check-in-sender).
	 */
	private const DESCRIPTION_LEAVE = 'LEAVE';

	protected CheckInMapper $mapper;
	protected CheckInDto $checkInDto;

	/** @var array<int, int[]> */
	private array $headIdsByUser = [];

	public function __construct()
	{
		$this->init();
	}

	abstract public function execute(CheckInDto $checkInDto): Result;

	protected function init(): void
	{
		$this->mapper = new CheckInMapper();
	}

	/**
	 * The creation time of a point comes from the client, which is not a trusted source: unbounded,
	 * it lets a write put a whole day of presence backwards, and that day is read as a real track by
	 * the map of a manager and by the daily stats. The bounds are the ones the offline queue of the
	 * client explains, so a point it legitimately held back is still accepted.
	 *
	 * Both write-paths share it: what a batch refuses a single call must refuse too, or the bound is
	 * only as strong as the cheapest way around it.
	 *
	 * @return Error|null an error the write is refused with, null when the point is accepted
	 */
	protected static function fitDateCreateToWindow(CheckInDto $dto): ?Error
	{
		if ($dto->dateCreate === null)
		{
			return null;
		}

		$now = time();
		$timestamp = $dto->dateCreate->getTimestamp();

		if ($timestamp > $now + self::FUTURE_TOLERANCE_SEC)
		{
			$dto->setDateCreate(new DateTime());

			return null;
		}

		if ($dto->description === self::DESCRIPTION_LEAVE)
		{
			return null;
		}

		if ($timestamp < $now - self::MAX_POINT_AGE_SEC)
		{
			return new Error('Check-in is older than the accepted offline window');
		}

		return null;
	}

	public function sendPushToDepartment(Pull\PushCommand $command, array $extraPayload = []): void
	{
		$departmentIds = DepartmentProvider::getDepartmentIdsByUserId($this->checkInDto->userId);
		$checkIn = $this->checkInDto->toArray();
		$checkIn['description'] = Emoji::decode($checkIn['description'] ?? '');

		if (!empty($this->checkInDto->fileIds))
		{
			$checkIn['files'] = (new FileResolver())->resolve(
				$this->checkInDto->id,
				$this->checkInDto->fileIds,
				$this->checkInDto->userId,
			);
		}

		$payload = array_merge(
			[
				'checkIn' => $checkIn,
				'user' => UserService::getInstance()->getUser($this->checkInDto->userId),
				'departmentIds' => $departmentIds,
			],
			$extraPayload,
		);

		Pull\PushSender::sendByTag(
			Pull\Tag::getUserTag($this->checkInDto->userId),
			$command,
			$payload,
		);

		foreach ($this->getHeadIds($this->checkInDto->userId) as $headId)
		{
			Pull\PushSender::sendByTag(
				Pull\Tag::getUserTag($headId),
				$command,
				$payload,
			);
		}
	}

	/**
	 * Heads the check-in push is addressed to, resolved once per command run: the same list also
	 * decides which client versions will parse the push payload.
	 *
	 * @return int[]
	 */
	protected function getHeadIds(int $userId): array
	{
		return $this->headIdsByUser[$userId] ??= DepartmentProvider::getDepartmentHeadsIdsByUserId($userId);
	}

	/**
	 * The push goes to the author and to their heads, and the delta is parsed by the client of each
	 * recipient, whose version follows that recipient's own rollout flag. So the delta is needed as
	 * soon as the rollout is on for at least one of them, not only for the author.
	 *
	 * Resolving the flags can walk the department tree, so callers run this before their transaction.
	 */
	protected function isDeltaNeededByRecipients(int $userId): bool
	{
		return $this->isRolloutEnabledForAny(array_merge([$userId], $this->getHeadIds($userId)));
	}

	/**
	 * The batch push reaches the same recipients, but the author is never one of its consumers: the
	 * client drops its own batch push on purpose, because its own auto points already reach the map
	 * through the check-in slice, which reads the check-ins of the push and never its delta. So only
	 * the heads decide here - during a staged rollout the author alone is no reason to build a delta
	 * nobody parses.
	 *
	 * Resolving the flags can walk the department tree, so callers run this before their transaction.
	 */
	protected function isBatchDeltaNeededByRecipients(int $userId): bool
	{
		return $this->isRolloutEnabledForAny($this->getHeadIds($userId));
	}

	/**
	 * @param int[] $recipientIds
	 */
	private function isRolloutEnabledForAny(array $recipientIds): bool
	{
		if (empty($recipientIds))
		{
			return false;
		}

		return in_array(true, Feature::filterCheckInRolloutEnabled($recipientIds), true);
	}

	/**
	 * Sends a single aggregated push for a batch of a user's check-in points.
	 * Department/head lookup is performed once per batch.
	 *
	 * @param CheckInDto[] $checkInDtos
	 * @param array $extraPayload the stats delta, same role as in {@see sendPushToDepartment()}
	 */
	public function sendBatchPushToDepartment(array $checkInDtos, array $extraPayload = []): void
	{
		if (empty($checkInDtos))
		{
			return;
		}

		$userId = $checkInDtos[0]->userId;
		$departmentIds = DepartmentProvider::getDepartmentIdsByUserId($userId);

		$checkIns = [];
		foreach ($checkInDtos as $checkInDto)
		{
			$checkIn = $checkInDto->toArray();
			$checkIn['description'] = Emoji::decode($checkIn['description'] ?? '');

			if (!empty($checkInDto->fileIds))
			{
				$checkIn['files'] = (new FileResolver())->resolve(
					$checkInDto->id,
					$checkInDto->fileIds,
					$checkInDto->userId,
				);
			}

			$checkIns[] = $checkIn;
		}

		$payload = array_merge(
			[
				'checkIns' => $checkIns,
				'user' => UserService::getInstance()->getUser($userId),
				'departmentIds' => $departmentIds,
			],
			$extraPayload,
		);

		Pull\PushSender::sendByTag(
			Pull\Tag::getUserTag($userId),
			Pull\PushCommand::CHECK_IN_BATCH_ADD,
			$payload,
		);

		foreach ($this->getHeadIds($userId) as $headId)
		{
			Pull\PushSender::sendByTag(
				Pull\Tag::getUserTag($headId),
				Pull\PushCommand::CHECK_IN_BATCH_ADD,
				$payload,
			);
		}
	}
}
