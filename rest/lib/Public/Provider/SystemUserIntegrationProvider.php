<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Provider;

use Bitrix\Main\Application;
use Bitrix\Rest\Internal\Contract\Repository\IncomingWebhookRepositoryInterface;
use Bitrix\Rest\Internal\Entity\IncomingWebhook\IncomingWebhook;
use Bitrix\Rest\Internal\Entity\SystemUser\ResourceType;
use Bitrix\Rest\Internal\Repository\Application\AppRepository;
use Bitrix\Rest\Internal\Repository\IncomingWebhookRepository;
use Bitrix\Rest\Internal\Repository\Mapper\SystemUserMapper;
use Bitrix\Rest\Internal\Repository\SystemUser\SystemUserRepository;
use Bitrix\Rest\Public\Contract\SystemUser\SystemUserIntegration;

class SystemUserIntegrationProvider
{
	private const WEBHOOK_BATCH_SIZE = 50;

	public function __construct(
		private readonly SystemUserRepository $systemUserRepository = new SystemUserRepository(new SystemUserMapper()),
		private readonly AppRepository $appRepository = new AppRepository(),
		private readonly IncomingWebhookRepositoryInterface $webhookRepository = new IncomingWebhookRepository(),
	)
	{
	}

	/**
	 * Applications and incoming webhooks bound to the system user.
	 *
	 * @return SystemUserIntegration[]
	 */
	public function getByUserId(int $userId): array
	{
		$integrations = [];

		$application = $this->getApplication($userId);
		if ($application !== null)
		{
			$integrations[] = $application;
		}

		foreach ($this->getWebhooks($userId) as $webhook)
		{
			$integrations[] = SystemUserIntegration::forWebhook((int)$webhook->getId(), (string)$webhook->getTitle());
		}

		return $integrations;
	}

	private function getApplication(int $userId): ?SystemUserIntegration
	{
		$systemUser = $this->systemUserRepository->getByUserIdAndResourceType($userId, ResourceType::APPLICATION);
		if ($systemUser === null)
		{
			return null;
		}

		$application = $this->appRepository->getById($systemUser->getResourceId());
		if ($application === null)
		{
			return null;
		}

		return SystemUserIntegration::forApplication((int)$application->getId(), (string)$application->getAppName());
	}

	/**
	 * RESOURCE_ID of a WEBHOOK system user points to the original user, so webhooks are read by USER_ID only.
	 *
	 * @return IncomingWebhook[]
	 */
	private function getWebhooks(int $userId): array
	{
		$webhooks = [];
		$offset = 0;

		while (true)
		{
			$batch = $this->webhookRepository->getListByUser($userId, $offset, self::WEBHOOK_BATCH_SIZE);
			array_push($webhooks, ...$batch);

			if (count($batch) < self::WEBHOOK_BATCH_SIZE)
			{
				break;
			}

			$offset += self::WEBHOOK_BATCH_SIZE;
		}

		return $webhooks;
	}

	protected function writeToLog(\Throwable $exception): void
	{
		Application::getInstance()->getExceptionHandler()->writeToLog($exception);
	}
}
