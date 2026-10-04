<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Install;

use Bitrix\BizprocDesigner\Internal\Service\Container;
use Bitrix\Main\Loader;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Rest\APAuth\PasswordTable;
use Bitrix\Rest\Enum\APAuth\PasswordType;
use Bitrix\Rest\Internal\Entity\IncomingWebhook\WebhookType;
use Bitrix\Rest\Internal\Repository\IncomingWebhookRepository;
use Bitrix\Rest\Public\Command\IncomingWebhook\DeactivateIncomingWebhookCommand;
use Bitrix\Rest\Public\Provider\Dto\IncomingWebhookDto;
use Bitrix\Rest\Public\Provider\IncomingWebhookProvider;
use Bitrix\Rest\Public\Provider\Params\IncomingWebhookFilter;
use Bitrix\Rest\Public\Provider\Params\IncomingWebhookParams;
use Throwable;

/**
 * Periodic CAgent gardener for the System webhook tokens of the external AI agent.
 *
 * Two passes per run, both scoped to our tokens (type=System + the ATTR_OWNER='Y' marker
 * attribute; foreign/user tokens are not touched):
 *  1. Deactivating expired ones: for an active token the expire_at attribute is read back (the
 *     provider does not return attributes, so the entity is re-read via IncomingWebhookRepository);
 *     if the term has passed, the token is deactivated by the rest command with the userId TAKEN
 *     FROM THE TOKEN ITSELF (the owner match passes).
 *  2. Physical deletion of long-inactive deactivated ones: a deactivated token is removed once it
 *     has been inactive for more than 7 days, measured from its last use (DATE_LOGIN) or, absent
 *     that, its creation (DATE_CREATE) — there is no separate revocation timestamp, so the window is
 *     counted from last activity/creation rather than from the moment of revocation. Before delete
 *     the token is re-read and re-checked for WebhookType::System + the marker attribute, then
 *     removed by the standard ORM PasswordTable::delete; external attributes are cascade-removed by
 *     onAfterDelete.
 *
 * Purpose: storage hygiene and removing the residual risk of the rest daily auth cache, NOT the
 * immediacy of revocation (that is guaranteed by the fresh check on call, AgentTokenGuard).
 *
 * Run resilience: DeactivateIncomingWebhookCommand::run() and PasswordTable::delete() throw
 * exceptions; every operation is wrapped in try/catch, so a failure on one token is logged and does
 * not interrupt the run.
 */
final class AgentTokenGardenerAgent
{
	private const ATTR_OWNER = 'bizprocdesigner';
	private const ATTR_EXPIRE_AT = 'expire_at';

	private const BATCH_LIMIT = 500;

	private const REVOKED_RETENTION_SECONDS = 7 * 86400;

	public function __construct(
		private readonly int $batchLimit = self::BATCH_LIMIT,
	)
	{
	}

	public static function execute(): string
	{
		if (Loader::includeModule('rest'))
		{
			(new self())->run();
		}

		return self::getAgentName();
	}

	public function run(): void
	{
		$this->deactivateExpired();
		$this->deleteLongRevoked();
	}

	public static function getAgentName(): string
	{
		return self::class . '::execute();';
	}

	/**
	 * Pass 1: deactivation of expired active System tokens.
	 */
	private function deactivateExpired(): void
	{
		$now = time();
		$repository = new IncomingWebhookRepository();

		foreach ($this->listOwnedTokens() as $dto)
		{
			if (!$dto->isActive())
			{
				continue;
			}

			if (!$this->isExpired($repository, $dto->getId(), $now))
			{
				continue;
			}

			try
			{
				(new DeactivateIncomingWebhookCommand(
					userId: $dto->getUserId(),
					passwordId: $dto->getId(),
				))->run();
			}
			catch (Throwable $e)
			{
				// a failure on one token does not break the run: an expired token is removed by the
				// TTL check on call, and the deactivation is retried by the next gardener run
				Container::getDefaultLogger()->error(
					'AI agent token gardener failed to deactivate expired token '
					. $dto->getId() . ': ' . $e->getMessage(),
				);
			}
		}
	}

	/**
	 * Pass 2: physical deletion of deactivated System tokens inactive for more than 7 days, where
	 * inactivity is measured from last use (DATE_LOGIN) or creation (DATE_CREATE), not from the
	 * moment of revocation (there is no separate revocation timestamp).
	 */
	private function deleteLongRevoked(): void
	{
		$threshold = time() - self::REVOKED_RETENTION_SECONDS;
		$repository = new IncomingWebhookRepository();

		foreach ($this->listOwnedTokens() as $dto)
		{
			if ($dto->isActive())
			{
				continue;
			}

			if ($this->getTokenTimestamp($dto) > $threshold)
			{
				continue;
			}

			$webhook = $repository->getById($dto->getId());
			if (
				$webhook === null
				|| $webhook->getType() !== WebhookType::System
				|| $webhook->getExternalAttribute(self::ATTR_OWNER)?->getValue() !== 'Y'
			)
			{
				continue;
			}

			try
			{
				PasswordTable::delete($dto->getId());
			}
			catch (Throwable $e)
			{
				// a failure on one delete does not break the run: the token is removed by the next gardener run
				Container::getDefaultLogger()->error(
					'AI agent token gardener failed to delete revoked token '
					. $dto->getId() . ': ' . $e->getMessage(),
				);
			}
		}
	}

	/**
	 * Our System tokens of all users (userId is not set, iterating over all owners), walked page by
	 * page so the whole set is processed rather than only the first batch: under the provider's default
	 * ID DESC order a single page would always keep the newest tokens and the oldest ones (lowest ids)
	 * beyond the page size would never be reached. The full snapshot is collected before the pass
	 * mutates anything, so offset paging stays stable while tokens are being deactivated or deleted.
	 *
	 * The provider returns both active and inactive DTOs; filtering by isActive() is done in place.
	 *
	 * @return IncomingWebhookDto[]
	 */
	private function listOwnedTokens(): array
	{
		$provider = new IncomingWebhookProvider();
		$tokens = [];
		$offset = 0;

		do
		{
			$page = $provider->getList(new IncomingWebhookParams(
				webhookFilter: new IncomingWebhookFilter(
					attributes: [self::ATTR_OWNER => 'Y'],
					type: PasswordType::System,
				),
				pager: new Pager(limit: $this->batchLimit, offset: $offset),
			));

			foreach ($page as $dto)
			{
				$tokens[] = $dto;
			}

			$offset += $this->batchLimit;
		}
		while (count($page) === $this->batchLimit);

		return $tokens;
	}

	/**
	 * Reads back the expire_at attribute (the provider does not carry attributes) and compares it
	 * with the current time. A missing/empty expire_at is treated as "not expired": a token without
	 * a term is not deactivated.
	 */
	private function isExpired(IncomingWebhookRepository $repository, int $passwordId, int $now): bool
	{
		$webhook = $repository->getById($passwordId);
		$expireAt = $webhook?->getExternalAttribute(self::ATTR_EXPIRE_AT)?->getValue();

		if ($expireAt === null || $expireAt === '')
		{
			return false;
		}

		return (int)$expireAt <= $now;
	}

	/**
	 * The last-activity timestamp used as the base of the retention window: DATE_LOGIN (last use),
	 * otherwise DATE_CREATE. This is not the revocation moment — no separate revocation timestamp is
	 * stored — so a token created and immediately deactivated without ever being used is counted from
	 * its creation. When both dates are absent it returns the current time: the token is considered
	 * "fresh" and is not deleted.
	 */
	private function getTokenTimestamp(IncomingWebhookDto $dto): int
	{
		$date = $dto->getDateLogin() ?? $dto->getDateCreate();

		return $date?->getTimestamp() ?? time();
	}
}
