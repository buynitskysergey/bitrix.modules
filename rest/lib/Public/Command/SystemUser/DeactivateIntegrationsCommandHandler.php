<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Command\SystemUser;

use Bitrix\Main;
use Bitrix\Main\Localization\Loc;
use Bitrix\Rest\Public\Command\Application\DeactivateSystemUserCommand;
use Bitrix\Rest\Public\Command\IncomingWebhook\DeactivateIncomingWebhookCommand;
use Bitrix\Rest\Public\Contract\SystemUser\SystemUserIntegration;
use Bitrix\Rest\Public\Provider\SystemUserIntegrationProvider;

class DeactivateIntegrationsCommandHandler
{
	public function __construct(
		private readonly SystemUserIntegrationProvider $provider = new SystemUserIntegrationProvider(),
	)
	{
	}

	public function __invoke(DeactivateIntegrationsCommand $command): Main\Result
	{
		$result = new Main\Result();

		$integrations = $this->provider->getByUserId($command->userId);
		if ($integrations === [])
		{
			return $result;
		}

		$connection = Main\Application::getConnection();
		$connection->startTransaction();
		$currentIntegration = null;

		try
		{
			foreach ($integrations as $integration)
			{
				$currentIntegration = $integration;
				$integrationResult = $this->deactivate($command->userId, $integration);
				if (!$integrationResult->isSuccess())
				{
					$connection->rollbackTransaction();
					$this->writeToLog(
						$command->userId,
						$integration,
						new Main\SystemException(implode('; ', $integrationResult->getErrorMessages())),
					);

					return $result->addError($this->createDeactivationError());
				}
			}
		}
		catch (\Throwable $e)
		{
			$connection->rollbackTransaction();
			$this->writeToLog($command->userId, $currentIntegration, $e);

			return $result->addError($this->createDeactivationError());
		}

		$connection->commitTransaction();

		return $result;
	}

	protected function writeToLog(int $userId, ?SystemUserIntegration $integration, \Throwable $exception): void
	{
		$context = 'user ' . $userId;
		if ($integration !== null)
		{
			$context .= ', integration ' . $integration->getType() . ' #' . $integration->getId();
		}

		Main\Application::getInstance()->getExceptionHandler()->writeToLog(
			new Main\SystemException(
				'Rest: system user integrations stay active after a failed deactivation (' . $context . ')',
				0,
				__FILE__,
				__LINE__,
				$exception,
			),
		);
	}

	private function deactivate(int $userId, SystemUserIntegration $integration): Main\Result
	{
		return match ($integration->getType())
		{
			SystemUserIntegration::TYPE_APP =>
				(new DeactivateSystemUserCommand($integration->getId()))->run(),
			SystemUserIntegration::TYPE_WEBHOOK =>
				(new DeactivateIncomingWebhookCommand($userId, $integration->getId()))->run(),
			default => $this->handleUnknownType($userId, $integration),
		};
	}

	private function handleUnknownType(int $userId, SystemUserIntegration $integration): Main\Result
	{
		$this->writeToLog(
			$userId,
			$integration,
			new Main\SystemException('Unknown system user integration type: ' . $integration->getType()),
		);

		return (new Main\Result())->addError($this->createDeactivationError());
	}

	private function createDeactivationError(): Main\Error
	{
		return new Main\Error(
			'Unknown system user integration type',
			'REST_SYSTEM_USER_DEACTIVATE_INTEGRATIONS_ERROR',
		);
	}
}
