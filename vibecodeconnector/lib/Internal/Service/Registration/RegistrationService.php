<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Registration;

use Bitrix\Main\Application;
use Bitrix\Main\Diag\ExceptionHandlerLog;
use Bitrix\Vibecodeconnector\Internal\Entity\Pairing\Pairing;
use Bitrix\Vibecodeconnector\Internal\Repository\Pairing\PairingRepository;
use Bitrix\Vibecodeconnector\Internal\Service\Messenger\Message\UserListPullMessage;
use Bitrix\Vibecodeconnector\Internal\Service\Messenger\UserListPullQueueGuard;
use Bitrix\Vibecodeconnector\Internal\Service\Auth\CloudSharedVerifier;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\Deactivation\PairingCatalogDeactivator;
use Bitrix\Vibecodeconnector\Internal\Service\Endpoint\EndpointResolver;
use Bitrix\Vibecodeconnector\Internal\Service\Endpoint\EndpointUrlGuard;
use Bitrix\Vibecodeconnector\Internal\Service\PublicKey\PublicKeySource;

final class RegistrationService
{
	private readonly \Closure $registrationRequest;
	private readonly ?UserListPullQueueGuard $userListPullQueueGuard;

	public function __construct(
		private readonly PairingRepository $repository = new PairingRepository(),
		private readonly PairingSettings $settings = new PairingSettings(),
		private readonly CloudSharedVerifier $cloudSharedVerifier = new CloudSharedVerifier(),
		private readonly PairingCatalogDeactivator $catalogDeactivator = new PairingCatalogDeactivator(),
		private readonly EndpointUrlGuard $urlGuard = new EndpointUrlGuard(),
		?\Closure $registrationRequest = null,
		?UserListPullQueueGuard $userListPullQueueGuard = null,
	) {
		$this->registrationRequest = $registrationRequest
			?? static fn (string $url): RegistrationResponse => (new RegistrationClient(new EndpointResolver($url)))->register();
		$this->userListPullQueueGuard = $userListPullQueueGuard;
	}

	public function register(string $endpointUrl): Pairing
	{
		$url = $endpointUrl;
		$this->urlGuard->assertValid($url);

		$response = ($this->registrationRequest)($url);
		if (!$response instanceof RegistrationResponse)
		{
			throw new \UnexpectedValueException('Invalid registration response');
		}

		$now = time();
		$pairing = new Pairing(
			iss: $response->iss,
			publicKey: $response->publicKey,
			portalId: $response->portalId,
			endpointUrl: $url,
			fetchedAt: $now,
			expiresAt: $this->settings->computeExpiresAt($now, $response->ttlSeconds),
			publicKeyTtl: $response->ttlSeconds,
			keySource: PublicKeySource::default(),
		);
		$this->repository->upsert($pairing);
		$message = new UserListPullMessage(
			pairingIss: $pairing->iss,
			endpointUrl: $pairing->endpointUrl,
		);
		$userListPullQueueGuard = $this->userListPullQueueGuard ?? new UserListPullQueueGuard();
		try
		{
			$enqueued = $userListPullQueueGuard->tryEnqueue($message);
		}
		catch (\Throwable)
		{
			$enqueued = false;
		}

		if (!$enqueued)
		{
			$this->logInitialPullFailure();
		}

		return $pairing;
	}

	private function logInitialPullFailure(): void
	{
		try
		{
			Application::getInstance()->getExceptionHandler()->writeToLog(
				new \RuntimeException('Unable to enqueue initial user list pull'),
				ExceptionHandlerLog::CAUGHT_EXCEPTION,
			);
		}
		catch (\Throwable)
		{
		}
	}

	public function unregister(string $iss): void
	{
		$pairing = $this->repository->findByIss($iss);
		if ($pairing === null)
		{
			$this->catalogDeactivator->deactivateByIss($iss);
			$this->repository->deleteByIss($iss);

			return;
		}

		(new RegistrationClient(new EndpointResolver($pairing->endpointUrl)))->unregister($iss);
		$this->catalogDeactivator->deactivateByIss($iss);
		$this->repository->deleteByIss($iss);
	}

	public function isRegistered(): bool
	{
		return $this->repository->hasAny();
	}

	public function isCloudSharedConfigured(): bool
	{
		return $this->cloudSharedVerifier->isConfigured();
	}

	/**
	 * The slot serves this portal even before the key is stored: the key is obtained
	 * on the first incoming request.
	 */
	public function isCloudSharedAvailable(): bool
	{
		return $this->cloudSharedVerifier->isSlotAvailable();
	}

	public function listPairings(): array
	{
		return $this->repository->listAll();
	}
}
