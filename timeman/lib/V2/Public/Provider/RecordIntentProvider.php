<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Provider;

use Bitrix\Timeman\V2\Internal\DI\Container;
use Bitrix\Timeman\V2\Internal\Service\RecordIntentService;
use Bitrix\Timeman\V2\Public\Dto\RecordIntent\RecordIntentSchedule;

/**
 * Read-only access to the welcome-box display state (DTO-01) for a user.
 *
 * The provider only reads through {@see RecordIntentService}; the sole write it triggers is the
 * idempotent first-use stamp performed inside the service. Mobile eligibility is intentionally NOT
 * checked here — that is the consumer layer's responsibility.
 */
final class RecordIntentProvider
{
	private readonly RecordIntentService $service;

	public function __construct()
	{
		$this->service = Container::getInstance()->getRecordIntentService();
	}

	public function getSchedule(int $userId): RecordIntentSchedule
	{
		$envelope = $this->service->resolveSchedule($userId);

		return RecordIntentSchedule::mapFromArray($envelope);
	}
}
