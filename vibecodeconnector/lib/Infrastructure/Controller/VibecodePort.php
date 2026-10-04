<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Infrastructure\Controller;

use Bitrix\Main\Application;
use Bitrix\Main\Command\Exception\CommandException;
use Bitrix\Main\Diag\ExceptionHandlerLog;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Engine\ActionFilter\Csrf;
use Bitrix\Main\Engine\ActionFilter\HttpMethod;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\Response;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main;
use Bitrix\Main\Request;
use Bitrix\Vibecodeconnector\Infrastructure\Controller\ActionFilter\CheckIncomingJwt;
use Bitrix\Vibecodeconnector\Internal\Exception\ProvisioningFailedException;
use Bitrix\Vibecodeconnector\Internal\Integration\Bitrix24\VibeDemo\VibeDemoActivatorFactory;
use Bitrix\Vibecodeconnector\Internal\Integration\Socialservices\NetworkService;
use Bitrix\Vibecodeconnector\Internal\Repository\Pairing\PairingRepository;
use Bitrix\Vibecodeconnector\Internal\Service\Messenger\Message\UserListPullMessage;
use Bitrix\Vibecodeconnector\Internal\Service\Messenger\UserListPullQueueGuard;
use Bitrix\Vibecodeconnector\Internal\Service\DeveloperKeyService;
use Bitrix\Vibecodeconnector\Internal\Service\Licensing\ProvisioningAccessGate;
use Bitrix\Vibecodeconnector\Internal\Service\Licensing\VibeDemo\Activator;
use Bitrix\Vibecodeconnector\Internal\Service\Provisioning\ApiKey;
use Bitrix\Vibecodeconnector\Internal\Service\Provisioning\ApplicationKey;
use Bitrix\Vibecodeconnector\Internal\Service\Provisioning\EntryPoint;
use Bitrix\Vibecodeconnector\Internal\Service\User\UserAttributesResolver;
use Bitrix\Vibecodeconnector\Internal\Service\User\UserEventTargetProvider;
use Throwable;

final class VibecodePort extends Controller implements IncomingServerAware
{
	private const USER_ATTRIBUTES_LIMIT = 200;

	private NetworkService $networkService;
	private ?string $incomingServerIss = null;
	private readonly ?UserListPullQueueGuard $userListPullQueueGuard;
	private ProvisioningAccessGate $accessGate;
	private ?Activator $vibeDemoActivator = null;

	public function __construct(?Request $request = null, ?UserListPullQueueGuard $userListPullQueueGuard = null)
	{
		$this->userListPullQueueGuard = $userListPullQueueGuard;
		parent::__construct($request);
	}

	public function init(): void
	{
		parent::init();
		$this->networkService = new NetworkService();
		$this->accessGate = new ProvisioningAccessGate();
	}

	public function setIncomingServerIss(?string $iss): void
	{
		$this->incomingServerIss = $iss;
	}

	public function setAccessGate(ProvisioningAccessGate $accessGate): void
	{
		$this->accessGate = $accessGate;
	}

	public function setVibeDemoActivator(Activator $vibeDemoActivator): void
	{
		$this->vibeDemoActivator = $vibeDemoActivator;
	}

	protected function getDefaultPreFilters()
	{
		return [
			new HttpMethod([HttpMethod::METHOD_POST]),
			new CheckIncomingJwt(),
			new Csrf(false),
		];
	}

	public function configureActions(): array
	{
		return [
			'ping' => [
				'-prefilters' => [
					CheckIncomingJwt::class,
				],
			],
			'getPortalNetworkId' => [
				'-prefilters' => [
					CheckIncomingJwt::class,
				],
			],
		];
	}

	public function getDeveloperKeyAction(int $userId): Response\AjaxJson
	{
		try
		{
			$developerKey = (new DeveloperKeyService(EntryPoint::vibecode($this->incomingServerIss)))->issueFor($userId);

			return Response\AjaxJson::createSuccess(
				['webhookUrl' => $developerKey->url],
			);
		}
		catch (ProvisioningFailedException $exception)
		{
			$this->addProvisioningError($exception, 'WEBHOOK_ISSUE_FAILED', userId: $userId);

			return Response\AjaxJson::createError($this->errorCollection);
		}
		catch (\Throwable $e)
		{
			$this->addError($this->classifyIssueError($e));

			return Response\AjaxJson::createError($this->errorCollection);
		}
	}

	public function getDeveloperKeyByNetworkUserIdAction(string $networkUserId): Response\AjaxJson
	{
		$userId = 0;
		try
		{
			$userId = $this->networkService->getUserIdByNetworkId($networkUserId);
			if ($userId === null)
			{
				$this->addError(new Error(
					'Portal user not found for the given network user id',
					'NETWORK_USER_NOT_FOUND',
				));

				return Response\AjaxJson::createError($this->errorCollection);
			}

			$developerKey = (new DeveloperKeyService(EntryPoint::vibecode($this->incomingServerIss)))->issueFor($userId);

			return Response\AjaxJson::createSuccess(
				['webhookUrl' => $developerKey->url],
			);
		}
		catch (ProvisioningFailedException $exception)
		{
			$this->addProvisioningError($exception, 'WEBHOOK_ISSUE_FAILED', userId: $userId);

			return Response\AjaxJson::createError($this->errorCollection);
		}
		catch (\Throwable $e)
		{
			$this->addError($this->classifyIssueError($e));

			return Response\AjaxJson::createError($this->errorCollection);
		}
	}

	/**
	 * Maps a developer-key issue failure to a specific machine-readable error code.
	 * Unlike the apiKey/appKey branches, a non-access CommandException is mapped to a
	 * code here instead of being rethrown, so every failure stays inside createError.
	 * Any unrecognized Throwable falls back to the stable WEBHOOK_ISSUE_FAILED code.
	 */
	private function classifyIssueError(\Throwable $e): Error
	{
		if ($e instanceof ProvisioningFailedException)
		{
			$code = $e->getErrorCode() ?? 'WEBHOOK_ISSUE_FAILED';
		}
		elseif ($e instanceof CommandException)
		{
			$previous = $e->getPrevious();
			$code = match (true)
			{
				$previous instanceof Main\AccessDeniedException => 'WEBHOOK_ISSUE_FORBIDDEN',
				$previous instanceof Main\ObjectNotFoundException => 'WEBHOOK_ISSUE_USER_NOT_FOUND',
				default => 'WEBHOOK_ISSUE_FAILED',
			};
		}
		else
		{
			$code = 'WEBHOOK_ISSUE_FAILED';
		}

		return new Error($e->getMessage(), $code);
	}

	/**
	 * @param string[] $scopes
	 */
	public function createApiKeyAction(int $userId, array $scopes, string $title): Response\AjaxJson
	{
		try
		{
			$webhookUrl = (new ApiKey\Issuer(
				EntryPoint::vibecode($this->incomingServerIss),
				accessGate: $this->accessGate,
			))->issue($userId, $scopes, $title);

			return Response\AjaxJson::createSuccess(['webhookUrl' => $webhookUrl]);
		}
		catch (ProvisioningFailedException $exception)
		{
			$this->addProvisioningError($exception, 'APIKEY_ISSUE_ACCESS_FAILED', userId: $userId);
		}
		catch (CommandException $exception)
		{
			$previousException = $exception->getPrevious();

			if ($previousException instanceof Main\AccessDeniedException)
			{
				$this->addError(new Error($previousException->getMessage(), 'APIKEY_ISSUE_ACCESS_FAILED'));
			}
			else
			{
				throw $previousException ?? $exception;
			}
		}
		catch (\Throwable $e)
		{
			$this->addError(new Error($e->getMessage(), 'APIKEY_ISSUE_FAILED'));
		}

		return Response\AjaxJson::createError($this->errorCollection);
	}

	/**
	 * @param string[] $scopes
	 * @param array<string, string> $menuTitles
	 */
	public function createApplicationKeyAction(
		int $userId,
		string $handlerUrl,
		array $scopes,
		string $title,
		bool $onlyApi = true,
		bool $mobile = false,
		string $installUrl = '',
		array $menuTitles = [],
		?string $applicationToken = null,
	): Response\AjaxJson
	{
		try
		{
			$app = (new ApplicationKey\Issuer(
				EntryPoint::vibecode($this->incomingServerIss),
				accessGate: $this->accessGate,
			))->issue(
				$userId,
				$handlerUrl,
				$scopes,
				$title,
				$onlyApi,
				$mobile,
				$installUrl,
				$menuTitles,
				$applicationToken,
			);

			return Response\AjaxJson::createSuccess([
				'clientId' => $app->getClientId(),
				'clientSecret' => $app->getClientSecret(),
				'applicationToken' => $app->getApplicationToken(),
				'appId' => (int)$app->getId(),
			]);
		}
		catch (ProvisioningFailedException $exception)
		{
			$this->addProvisioningError($exception, 'APPKEY_INSTALL_ACCESS_FAILED', userId: $userId);
		}
		catch (CommandException $exception)
		{
			$previousException = $exception->getPrevious();

			if ($previousException instanceof Main\AccessDeniedException)
			{
				$this->addError(new Error($previousException->getMessage(), 'APPKEY_INSTALL_ACCESS_FAILED'));
			}
			else
			{
				throw new $previousException();
			}
		}
		catch (\Throwable $e)
		{
			$this->addError(new Error($e->getMessage(), 'APPKEY_INSTALL_FAILED'));
		}

		return Response\AjaxJson::createError($this->errorCollection);
	}

	/**
	 * Cloud-shared sibling of createApiKey: identifies the portal user by their
	 * Bitrix24.Network global id (used on CLOUD portals where the caller has no
	 * portal-internal user id). Resolves to a local USER_ID, then issues exactly
	 * like createApiKey.
	 *
	 * @param string[] $scopes
	 */
	public function createApiKeyByNetworkUserIdAction(string $networkUserId, array $scopes, string $title): Response\AjaxJson
	{
		$userId = 0;
		try
		{
			$userId = $this->networkService->getUserIdByNetworkId($networkUserId);
			if ($userId === null)
			{
				$this->addError(new Error(
					'Portal user not found for the given network user id',
					'NETWORK_USER_NOT_FOUND',
				));

				return Response\AjaxJson::createError($this->errorCollection);
			}

			$webhookUrl = (new ApiKey\Issuer(
				EntryPoint::vibecode($this->incomingServerIss),
				accessGate: $this->accessGate,
			))->issue($userId, $scopes, $title);

			return Response\AjaxJson::createSuccess(['webhookUrl' => $webhookUrl]);
		}
		catch (ProvisioningFailedException $exception)
		{
			$this->addProvisioningError($exception, 'APIKEY_ISSUE_ACCESS_FAILED', userId: $userId);
		}
		catch (CommandException $exception)
		{
			$previousException = $exception->getPrevious();

			if ($previousException instanceof Main\AccessDeniedException)
			{
				$this->addError(new Error($previousException->getMessage(), 'APIKEY_ISSUE_ACCESS_FAILED'));
			}
			else
			{
				throw new $previousException();
			}
		}
		catch (\Throwable $e)
		{
			$this->addError(new Error($e->getMessage(), 'APIKEY_ISSUE_FAILED'));
		}

		return Response\AjaxJson::createError($this->errorCollection);
	}

	/**
	 * Cloud-shared sibling of createApplicationKey (see createApiKeyByNetworkUserId).
	 *
	 * @param string[] $scopes
	 * @param array<string, string> $menuTitles
	 */
	public function createApplicationKeyByNetworkUserIdAction(
		string $networkUserId,
		string $handlerUrl,
		array $scopes,
		string $title,
		bool $onlyApi = true,
		bool $mobile = false,
		string $installUrl = '',
		array $menuTitles = [],
		?string $applicationToken = null,
	): Response\AjaxJson
	{
		$userId = 0;
		try
		{
			$userId = $this->networkService->getUserIdByNetworkId($networkUserId);
			if ($userId === null)
			{
				$this->addError(new Error(
					'Portal user not found for the given network user id',
					'NETWORK_USER_NOT_FOUND',
				));

				return Response\AjaxJson::createError($this->errorCollection);
			}

			$app = (new ApplicationKey\Issuer(
				EntryPoint::vibecode($this->incomingServerIss),
				accessGate: $this->accessGate,
			))->issue(
				$userId,
				$handlerUrl,
				$scopes,
				$title,
				$onlyApi,
				$mobile,
				$installUrl,
				$menuTitles,
				$applicationToken,
			);

			return Response\AjaxJson::createSuccess([
				'clientId' => $app->getClientId(),
				'clientSecret' => $app->getClientSecret(),
				'applicationToken' => $app->getApplicationToken(),
				'appId' => (int)$app->getId(),
			]);
		}
		catch (ProvisioningFailedException $exception)
		{
			$this->addProvisioningError($exception, 'APPKEY_INSTALL_ACCESS_FAILED', userId: $userId);
		}
		catch (CommandException $exception)
		{
			$previousException = $exception->getPrevious();

			if ($previousException instanceof Main\AccessDeniedException)
			{
				$this->addError(new Error($previousException->getMessage(), 'APPKEY_INSTALL_ACCESS_FAILED'));
			}
			else
			{
				throw $previousException ?? $exception;
			}
		}
		catch (\Throwable $e)
		{
			$this->addError(new Error($e->getMessage(), 'APPKEY_INSTALL_FAILED'));
		}

		return Response\AjaxJson::createError($this->errorCollection);
	}

	public function checkConnectionAction(): Response\AjaxJson
	{
		return Response\AjaxJson::createSuccess(
			['OK'],
		);
	}

	public function userListChangedAction(int $version): Response\AjaxJson
	{
		$target = $this->getUserEventTargetProvider()->resolve((string)$this->incomingServerIss);
		if ($target === null)
		{
			$this->addError(new Error('Pairing not found', 'PAIRING_NOT_FOUND'));

			return Response\AjaxJson::createError($this->errorCollection);
		}

		try
		{
			$message = new UserListPullMessage(
				pairingIss: $target->iss,
				endpointUrl: $target->endpointUrl,
				version: $version,
			);
			$userListPullQueueGuard = $this->userListPullQueueGuard ?? new UserListPullQueueGuard();
			if (!$userListPullQueueGuard->tryEnqueue($message))
			{
				throw new \RuntimeException('User list pull queue is unavailable');
			}
		}
		catch (\Throwable)
		{
			$this->addError(new Error('Unable to enqueue user list pull', 'USER_LIST_PULL_ENQUEUE_FAILED'));

			return Response\AjaxJson::createError($this->errorCollection);
		}

		return Response\AjaxJson::createSuccess();
	}

	/**
	 * Read-only: no groupEventSequence increment, no event publication, no user registration.
	 * Not gated by the UserEvents feature flag - it has no side effects, and a silent method
	 * would break the vibecode-side registration flow on portals where the event channel is off.
	 *
	 * @param int[] $userIds
	 */
	public function getUserAttributesAction(array $userIds = []): Response\AjaxJson
	{
		try
		{
			if ($userIds === [])
			{
				$this->addError(new Error('User id list must not be empty', 'USER_ATTRIBUTES_INVALID_REQUEST'));

				return Response\AjaxJson::createError($this->errorCollection);
			}

			if (count($userIds) > self::USER_ATTRIBUTES_LIMIT)
			{
				$this->addError(new Error('Too many user ids requested', 'USER_ATTRIBUTES_LIMIT_EXCEEDED'));

				return Response\AjaxJson::createError($this->errorCollection);
			}

			foreach ($userIds as $userId)
			{
				if (!$this->isPositiveIntegerId($userId))
				{
					$this->addError(new Error('User ids must be positive integers', 'USER_ATTRIBUTES_INVALID_REQUEST'));

					return Response\AjaxJson::createError($this->errorCollection);
				}
			}

			$uniqueUserIds = array_values(array_unique(array_map('intval', $userIds)));
			$attributes = $this->getUserAttributesResolver()->getAttributesForUsers($uniqueUserIds);

			return Response\AjaxJson::createSuccess([
				'users' => array_map(
					static fn(array $item): array => [
						'bitrixUserId' => $item['userId'],
						'isAdmin' => $item['isAdmin'],
						'isIntegrator' => $item['isIntegrator'],
						'groupEventSequence' => $item['groupEventSequence'],
					],
					$attributes,
				),
			]);
		}
		catch (\Throwable $e)
		{
			Application::getInstance()->getExceptionHandler()->writeToLog(
				$e,
				ExceptionHandlerLog::CAUGHT_EXCEPTION,
			);
			$this->addError(new Error('Unable to resolve user attributes', 'USER_ATTRIBUTES_FAILED'));

			return Response\AjaxJson::createError($this->errorCollection);
		}
	}

	protected function getUserEventTargetProvider(): UserEventTargetProvider
	{
		return ServiceLocator::getInstance()->get(UserEventTargetProvider::class);
	}

	protected function getUserAttributesResolver(): UserAttributesResolver
	{
		return ServiceLocator::getInstance()->get(UserAttributesResolver::class);
	}

	private function isPositiveIntegerId(mixed $userId): bool
	{
		if (is_int($userId))
		{
			return $userId > 0;
		}

		return is_string($userId) && preg_match('/^[1-9][0-9]*$/', $userId) === 1;
	}

	public function pingAction(): Response\AjaxJson
	{
		return Response\AjaxJson::createSuccess(
			['OK'],
		);
	}

	public function getPortalNetworkIdAction(): Response\AjaxJson
	{
		if (!$this->networkService->isCloudPortal())
		{
			$this->addError(new Error(
				'Portal network id is available on cloud portals only',
				'NOT_CLOUD_PORTAL',
			));

			return Response\AjaxJson::createError($this->errorCollection);
		}

		$networkId = $this->networkService->getPortalNetworkId();
		if ($networkId === null)
		{
			$this->addError(new Error(
				'Portal network id is not configured on this cloud portal',
				'NETWORK_ID_UNAVAILABLE',
			));

			return Response\AjaxJson::createError($this->errorCollection);
		}

		return Response\AjaxJson::createSuccess(['networkId' => $networkId]);
	}

	public function getVibeDemoStateAction(): Response\AjaxJson
	{
		return $this->createVibeDemoResponse(
			$this->getVibeDemoActivator()->getState(),
		);
	}

	public function activateVibeDemoAction(?int $days = null): Response\AjaxJson
	{
		return $this->createVibeDemoResponse(
			$this->getVibeDemoActivator()->activate($days, $this->incomingServerIss),
		);
	}

	public function resetVibeDemoAction(): Response\AjaxJson
	{
		return $this->createVibeDemoResponse(
			$this->getVibeDemoActivator()->reset($this->incomingServerIss),
		);
	}

	private function getVibeDemoActivator(): Activator
	{
		return $this->vibeDemoActivator ??= VibeDemoActivatorFactory::create();
	}

	private function createVibeDemoResponse(Main\Result $result): Response\AjaxJson
	{
		if ($result->isSuccess())
		{
			return Response\AjaxJson::createSuccess($result->getData());
		}

		return Response\AjaxJson::createError($result->getErrorCollection());
	}

	private function addProvisioningError(
		ProvisioningFailedException $exception,
		string $fallbackCode,
		int $userId = 0,
	): void
	{
		$errorCode = $exception->getErrorCode() ?? $fallbackCode;
		$customData = $errorCode === ProvisioningAccessGate::ERROR_CODE
			? $this->getVibePlusUpsellPayload($userId)
			: null;

		$this->addError(new Error(
			$exception->getMessage(),
			$errorCode,
			$customData,
		));
	}

	private function getVibePlusUpsellPayload(int $userId): ?array
	{
		try
		{
			if (!Loader::includeModule('bitrix24'))
			{
				return null;
			}

			$serviceLocator = ServiceLocator::getInstance();
			if (!$serviceLocator->has(\Bitrix\Bitrix24\Public\Service\VibePlus\UpsellProjectionProvider::class))
			{
				return null;
			}

			return $serviceLocator
				->get(\Bitrix\Bitrix24\Public\Service\VibePlus\UpsellProjectionProvider::class)
				->getProjectionForUser($userId)
				?->toArray()
			;
		}
		catch (\Throwable)
		{
			return null;
		}
	}
}
