<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Integration\Main\EventHandler;

use Bitrix\Main\Application;
use Bitrix\Main\Diag\ExceptionHandlerLog;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Vibecodeconnector\Internal\Entity\User\UserEventChange;
use Bitrix\Vibecodeconnector\Internal\Service\User\UserAttributesResolver;
use Bitrix\Vibecodeconnector\Internal\Service\User\UserEventPublisher;
use Bitrix\Vibecodeconnector\Internal\Service\User\UserEventsAvailability;

class UserEventHandler
{
	public static function onBeforeUserUpdate(array &$fields): void
	{
		if (!static::isUserEventsEnabled())
		{
			return;
		}

		$userId = (int)($fields['ID'] ?? 0);
		if ($userId <= 0)
		{
			return;
		}

		try
		{
			if (
				array_key_exists('ACTIVE', $fields)
				&& !array_key_exists('PREV_ACTIVE', $fields)
			)
			{
				$fields['PREV_ACTIVE'] = static::getCurrentActive($userId);
			}
		}
		catch (\Throwable $exception)
		{
			static::logException($exception);
		}
	}

	public static function onAfterUserUpdate(array $fields): void
	{
		try
		{
			if (($fields['RESULT'] ?? null) !== true)
			{
				return;
			}

			$userId = (int)($fields['ID'] ?? 0);
			if (
				array_key_exists('ACTIVE', $fields)
				&& array_key_exists('PREV_ACTIVE', $fields)
			)
			{
				$change = match ([$fields['PREV_ACTIVE'], $fields['ACTIVE']]) {
					['Y', 'N'] => UserEventChange::Fired,
					['N', 'Y'] => UserEventChange::Restored,
					default => null,
				};
				if ($change !== null)
				{
					static::publishSafely($userId, $change);
				}
			}

		}
		catch (\Throwable $exception)
		{
			static::logException($exception);
		}
	}

	public static function onAfterSetUserGroup(mixed $userId, array $groups): void
	{
		try
		{
			$userId = (int)$userId;
			$publisher = static::getPublisher();
			if ($userId <= 0 || !$publisher->hasUser($userId))
			{
				return;
			}

			$attributes = static::getResolver()->resolveForUser($userId);
			$publisher->publishGroupsChanged($userId, $attributes['isAdmin'], $attributes['isIntegrator']);
		}
		catch (\Throwable $exception)
		{
			static::logException($exception);
		}
	}

	public static function onAfterUserDelete(mixed $userId): void
	{
		try
		{
			static::getPublisher()->publish((int)$userId, UserEventChange::Deleted);
		}
		catch (\Throwable $exception)
		{
			static::logException($exception);
		}
	}

	protected static function getPublisher(): UserEventPublisher
	{
		return ServiceLocator::getInstance()->get(UserEventPublisher::class);
	}

	protected static function getResolver(): UserAttributesResolver
	{
		return ServiceLocator::getInstance()->get(UserAttributesResolver::class);
	}

	protected static function isUserEventsEnabled(): bool
	{
		return (new UserEventsAvailability())->isEnabled();
	}

	protected static function publishSafely(int $userId, UserEventChange $change): void
	{
		try
		{
			static::getPublisher()->publish($userId, $change);
		}
		catch (\Throwable $exception)
		{
			static::logException($exception);
		}
	}

	protected static function logException(\Throwable $exception): void
	{
		Application::getInstance()->getExceptionHandler()->writeToLog(
			$exception,
			ExceptionHandlerLog::CAUGHT_EXCEPTION,
		);
	}

	protected static function getCurrentActive(int $userId): ?string
	{
		$user = \Bitrix\Main\UserTable::query()
			->setSelect(['ACTIVE'])
			->where('ID', $userId)
			->setLimit(1)
			->fetch()
		;

		return is_array($user) ? ($user['ACTIVE'] ?? null) : null;
	}
}
