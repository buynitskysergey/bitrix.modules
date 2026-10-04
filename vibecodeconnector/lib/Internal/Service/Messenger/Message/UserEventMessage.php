<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Messenger\Message;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Messenger\Entity\AbstractMessage;
use Bitrix\Main\Messenger\Entity\MessageInterface;
use Bitrix\Vibecodeconnector\Internal\Entity\User\UserEventChange;

final class UserEventMessage extends AbstractMessage
{
	public function __construct(
		public string $pairingIss,
		public int $bitrixUserId,
		public UserEventChange $change,
		public int $occurredAt,
		public int $enqueuedAt,
		public ?bool $isAdmin = null,
		public ?bool $isIntegrator = null,
		public ?int $groupEventSequence = null,
		public ?int $firstAttemptAt = null,
		public bool $attemptPrepared = false,
		public int $attemptCount = 0,
	) {
		if ($this->change === UserEventChange::GroupsChanged)
		{
			if (
				$this->isAdmin === null
				|| $this->isIntegrator === null
				|| $this->groupEventSequence === null
				|| $this->groupEventSequence <= 0
			)
			{
				throw new \InvalidArgumentException(
					'Groups changed event requires admin flag, integrator flag and positive group event sequence',
				);
			}

			return;
		}

		if ($this->isAdmin !== null || $this->isIntegrator !== null || $this->groupEventSequence !== null)
		{
			throw new \InvalidArgumentException('Group event fields are only allowed for groups changed event');
		}
	}

	public static function createFromData(array $data): MessageInterface
	{
		try
		{
			if (!($data['change'] ?? null) instanceof UserEventChange)
			{
				$data['change'] = UserEventChange::from((string)($data['change'] ?? ''));
			}
			self::validateGroupFieldsInData($data, $data['change']);

			return parent::createFromData($data);
		}
		catch (ArgumentException $exception)
		{
			throw $exception;
		}
		catch (\InvalidArgumentException | \TypeError | \ValueError $exception)
		{
			throw new ArgumentException($exception->getMessage(), 'data', $exception);
		}
	}

	public function jsonSerialize(): mixed
	{
		$data = parent::jsonSerialize();
		$data['change'] = $this->change->value;
		if ($this->change !== UserEventChange::GroupsChanged)
		{
			unset($data['isAdmin'], $data['isIntegrator'], $data['groupEventSequence']);
		}

		return $data;
	}

	private static function validateGroupFieldsInData(array $data, UserEventChange $change): void
	{
		if ($change === UserEventChange::GroupsChanged)
		{
			if (
				!array_key_exists('isAdmin', $data)
				|| !is_bool($data['isAdmin'])
				|| !array_key_exists('isIntegrator', $data)
				|| !is_bool($data['isIntegrator'])
				|| !array_key_exists('groupEventSequence', $data)
				|| !is_int($data['groupEventSequence'])
				|| $data['groupEventSequence'] <= 0
			)
			{
				throw new \InvalidArgumentException(
					'Groups changed event requires boolean admin and integrator flags'
					. ' and positive integer group event sequence',
				);
			}

			return;
		}

		if (
			($data['isAdmin'] ?? null) !== null
			|| ($data['isIntegrator'] ?? null) !== null
			|| ($data['groupEventSequence'] ?? null) !== null
		)
		{
			throw new \InvalidArgumentException('Group event fields are only allowed for groups changed event');
		}
	}
}
