<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Command\IncomingWebhook;

use Bitrix\Main\AccessDeniedException;
use Bitrix\Main\Application;
use Bitrix\Main\Error;
use Bitrix\Main\ObjectNotFoundException;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Rest\APAuth\PasswordTable;
use Bitrix\Rest\APAuth\PermissionTable;
use Bitrix\Rest\Engine\Access\HoldEntity;
use Bitrix\Rest\Internal\Access\WebhookAccessChecker;
use Bitrix\Rest\Internal\Access\User\Model\RestUserModel;
use Bitrix\Rest\Internal\Contract\Repository\IncomingWebhookRepositoryInterface;
use Bitrix\Rest\Internal\Entity\IncomingWebhook\IncomingWebhook;
use Bitrix\Rest\Internal\Exception\IncomingWebhook\IncomingWebhookNotFoundException;
use Bitrix\Rest\Internal\Repository\IncomingWebhookRepository;
use Bitrix\Rest\Internal\Repository\IntegrationRepository;
use Bitrix\Rest\Internal\Service\Security\SecurityAuditLogger;
use Bitrix\Rest\Preset\Provider;
use Bitrix\Rest\Service\ServiceContainer;

class DeleteIncomingWebhookCommandHandler
{
	public function __construct(
		private IncomingWebhookRepositoryInterface $repository = new IncomingWebhookRepository(),
		private IntegrationRepository $integrationRepository = new IntegrationRepository(),
		private SecurityAuditLogger $securityAuditLogger = new SecurityAuditLogger(),

	)
	{
	}

	/**
	 * @throws AccessDeniedException
	 * @throws ObjectNotFoundException
	 * @throws IncomingWebhookNotFoundException
	 * @throws PersistenceException
	 */
	public function __invoke(DeleteIncomingWebhookCommand $command): void
	{
		$user = RestUserModel::createFromId($command->userId);
		if ($user->getData() === null)
		{
			throw new ObjectNotFoundException(
				'User with ID ' . $command->userId . ' not found'
			);
		}

		$webhook = $this->repository->getByWebhookId($command->webHookPassword);
		if ($webhook === null)
		{
			throw new IncomingWebhookNotFoundException();
		}

		$accessChecker = new WebhookAccessChecker($command->userId);
		if (!$accessChecker->canManageIncomingWebhook($webhook))
		{
			throw new AccessDeniedException(
				'User does not have rights to delete incoming webhook'
			);
		}
		$integration = $this->integrationRepository->getByIncomingWebhookPasswordId($webhook->getId());
		$providerFailed = false;
		$providerErrors = [];

		if ($integration === null)
		{
			try
			{
				$this->deleteWithoutIntegration($webhook);
			}
			catch (\Throwable $exception)
			{
				// The direct path is transactional: nothing is deleted on error, and the
				// failure is audited before the exception leaves the handler.
				$this->securityAuditLogger->logWebhookDeleted(
					actingUserId: $command->userId,
					webhookId: (int)$webhook->getId(),
					ownerUserId: $webhook->getUserId(),
					scopes: $webhook->getScopes(),
					outcome: SecurityAuditLogger::OUTCOME_FAILURE,
					webhookType: $webhook->getType(),
				);

				throw $exception;
			}
		}
		else
		{
			$providerResult = Provider::deleteIntegration($integration->getId(), $command->userId);
			$providerFailed = ($providerResult['result'] ?? null) === 'error';

			// The provider reports a failure as a list of messages, where a single item may itself
			// be the message list of a nested result.
			$providerMessages = (array)($providerResult['errors'] ?? []);
			array_walk_recursive(
				$providerMessages,
				static function (mixed $message) use (&$providerErrors): void {
					$providerErrors[] = new Error((string)$message);
				},
			);
		}

		// A failed provider call is audited too: it may have already destroyed the key.
		$this->securityAuditLogger->logWebhookDeleted(
			actingUserId: $command->userId,
			webhookId: (int)$webhook->getId(),
			ownerUserId: $webhook->getUserId(),
			scopes: $webhook->getScopes(),
			outcome: $providerFailed
				? SecurityAuditLogger::OUTCOME_PARTIAL_FAILURE
				: SecurityAuditLogger::OUTCOME_SUCCESS,
			webhookType: $webhook->getType(),
		);

		if ($providerFailed)
		{
			throw new PersistenceException(
				$this->describeFailure('Unable to delete incoming webhook integration', $providerErrors),
				errors: $providerErrors,
			);
		}
	}

	private function deleteWithoutIntegration(IncomingWebhook $webhook): void
	{
		$passwordId = (int)$webhook->getId();
		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			PermissionTable::deleteByFilter(['=PASSWORD_ID' => $passwordId]);

			$deleteResult = PasswordTable::delete($passwordId);
			if (!$deleteResult->isSuccess())
			{
				throw new PersistenceException(
					$this->describeFailure('Unable to delete incoming webhook', $deleteResult->getErrors()),
					errors: $deleteResult->getErrors(),
				);
			}

			$connection->commitTransaction();
		}
		catch (\Throwable $exception)
		{
			$connection->rollbackTransaction();

			throw $exception;
		}

		// Repeat invalidation: the one done inside the transaction may be outrun by a
		// parallel request caching the uncommitted state for the whole cache TTL.
		PasswordTable::cleanCache();
		PermissionTable::cleanCache();
		ServiceContainer::getInstance()->getAPAuthPasswordService()->clearCacheById($passwordId);

		$password = $webhook->getPassword();
		if (HoldEntity::is(HoldEntity::TYPE_WEBHOOK, $password))
		{
			HoldEntity::delete(HoldEntity::TYPE_WEBHOOK, $password);
			HoldEntity::checkBlockCode(HoldEntity::TYPE_WEBHOOK);
		}
	}

	/**
	 * The exception log prints only the message of every exception in the chain, so a reason kept
	 * apart in Error[] never reaches it.
	 *
	 * @param Error[] $errors
	 */
	private function describeFailure(string $message, array $errors): string
	{
		$reasons = [];
		foreach ($errors as $error)
		{
			$reason = trim($error->getMessage());
			if ($reason !== '')
			{
				$reasons[] = $reason;
			}
		}

		return $reasons === [] ? $message : $message . ': ' . implode('; ', $reasons);
	}
}
