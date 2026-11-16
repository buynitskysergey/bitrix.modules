<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Controller\ActionFilter;

use Bitrix\Main\Config\Option;
use Bitrix\Main\Context;
use Bitrix\Main\Data\Storage\StorageInterface;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Localization\Loc;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

class RemoteImageImportRateLimiter extends Base
{
	public const ERROR_CODE = 'REMOTE_IMAGE_IMPORT_LIMIT_EXCEEDED';
	public const OPTION_LIMIT = 'remote_image_import_limit_per_minute';
	public const DEFAULT_LIMIT = 60;

	private const MODULE_ID = 'note';
	private const STORAGE_KEY_PREFIX = 'note.remote_image_import.rate_limit.';
	private const WINDOW_SECONDS = 60;
	private const STATE_TTL_SECONDS = 2 * self::WINDOW_SECONDS;
	private const LOGGER_ID = 'note.remote_image_import_rate_limiter';
	private const HTTP_TOO_MANY_REQUESTS = 429;

	private readonly ?StorageInterface $storage;
	private readonly LoggerInterface $logger;
	private readonly \Closure $clock;

	public function __construct(
		?StorageInterface $storage = null,
		?LoggerInterface $logger = null,
		?\Closure $clock = null,
	)
	{
		parent::__construct();

		$this->storage = $storage;
		$this->logger = $logger
			?? (new LoggerFactory())->createById(self::LOGGER_ID, [], false)
			?? new NullLogger()
		;
		$this->clock = $clock ?? static fn(): int => time();
	}

	public function onBeforeAction(Event $event): ?EventResult
	{
		$userId = $this->getUserId();
		if ($userId <= 0)
		{
			return null;
		}

		try
		{
			$limit = $this->getLimit();
			$isAllowed = $this->countRequestWithinLimit($userId, $limit);
		}
		catch (\Throwable $error)
		{
			$this->logger->warning('Remote image import rate limiter failed open.', [
				'userId' => $userId,
				'exception' => $error::class,
			]);

			return null;
		}

		if ($isAllowed)
		{
			return null;
		}

		$this->logger->warning('Remote image import rate limit exceeded.', [
			'userId' => $userId,
			'limit' => $limit,
		]);
		$this->setTooManyRequestsStatus();
		$this->addError(new Error(
			$this->getErrorMessage(),
			self::ERROR_CODE,
		));

		return new EventResult(EventResult::ERROR, null, null, $this);
	}

	protected function getUserId(): int
	{
		return (int)$this->getAction()?->getController()?->getCurrentUser()?->getId();
	}

	protected function getLimit(): int
	{
		$limit = (int)Option::get(self::MODULE_ID, self::OPTION_LIMIT, (string)self::DEFAULT_LIMIT);

		return $limit > 0 ? $limit : self::DEFAULT_LIMIT;
	}

	protected function setTooManyRequestsStatus(): void
	{
		Context::getCurrent()?->getResponse()->setStatus(self::HTTP_TOO_MANY_REQUESTS);
	}

	protected function getErrorMessage(): string
	{
		return (string)Loc::getMessage('NOTE_REMOTE_IMAGE_IMPORT_RATE_LIMIT_EXCEEDED');
	}

	private function countRequestWithinLimit(int $userId, int $limit): bool
	{
		$window = intdiv(($this->clock)(), self::WINDOW_SECONDS);
		$key = self::STORAGE_KEY_PREFIX . $userId;
		$storage = $this->storage ?? ServiceLocator::getInstance()->get(StorageInterface::class);
		$stored = $storage->get($key, []);
		$count = is_array($stored) && (int)($stored['window'] ?? -1) === $window
			? max(0, (int)($stored['count'] ?? 0))
			: 0
		;
		if ($count >= $limit)
		{
			return false;
		}

		if (!$storage->set(
			$key,
			[
				'window' => $window,
				'count' => $count + 1,
			],
			self::STATE_TTL_SECONDS,
		))
		{
			throw new \RuntimeException('Remote image import rate limit state was not stored.');
		}

		return true;
	}
}
