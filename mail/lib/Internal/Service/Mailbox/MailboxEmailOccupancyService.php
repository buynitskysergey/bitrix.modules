<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Mailbox;

use Bitrix\Mail\Helper\Enum\MailboxStatus;
use Bitrix\Mail\Internals\Repository\MailboxAddressAliasRepository;
use Bitrix\Mail\Internals\Service\Mailbox\EmailNormalizer;
use Bitrix\Mail\MailboxTable;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Query\Query;

/**
 * Tells whether an email address is already taken by an active mailbox or reserved by its history.
 */
final class MailboxEmailOccupancyService
{
	private readonly MailboxAddressAliasRepository $aliasRepository;
	private readonly EmailNormalizer $emailNormalizer;

	public function __construct(
		?MailboxAddressAliasRepository $aliasRepository = null,
		?EmailNormalizer $emailNormalizer = null,
	)
	{
		$this->aliasRepository = $aliasRepository ?? new MailboxAddressAliasRepository();
		$this->emailNormalizer = $emailNormalizer ?? new EmailNormalizer();
	}

	public function checkOccupancy(
		string $email,
		int $requestingUserId,
		?int $excludeMailboxId = null,
	): MailboxEmailOccupancy
	{
		$normalizedEmail = $this->normalizeEmail($email);
		if ($normalizedEmail === '')
		{
			return MailboxEmailOccupancy::Free;
		}

		$mailbox = $this->buildEarliestActiveMailboxQuery($normalizedEmail, $excludeMailboxId)
			->setSelect(['USER_ID'])
			->fetch()
		;

		if ($mailbox)
		{
			return (int)$mailbox['USER_ID'] === $requestingUserId
				? MailboxEmailOccupancy::OccupiedByRequester
				: MailboxEmailOccupancy::OccupiedByOther
			;
		}

		return $this->checkAliasOccupancy($normalizedEmail, $requestingUserId, $excludeMailboxId);
	}

	/**
	 * Whether this user already has an active mailbox with the address. This asks about one person,
	 * not about the portal, and is not the ban on a second connection: an operation that creates no
	 * record is free to proceed over an address held by someone else, but still may not leave one
	 * person with two mailboxes of the same address.
	 */
	public function isAddressHeldByUser(string $email, int $userId, ?int $excludeMailboxId = null): bool
	{
		return $this->findActiveMailboxIdOfUser($email, $userId, $excludeMailboxId) !== null;
	}

	/**
	 * Identifier of the oldest connected mailbox of this user holding the address. Answers the question
	 * of isAddressHeldByUser() for callers that need the mailbox itself and not just a yes or no.
	 */
	public function findActiveMailboxIdOfUser(string $email, int $userId, ?int $excludeMailboxId = null): ?int
	{
		$normalizedEmail = $this->normalizeEmail($email);
		if ($normalizedEmail === '')
		{
			return null;
		}

		$mailbox = $this->buildEarliestActiveMailboxQuery($normalizedEmail, $excludeMailboxId)
			->where('USER_ID', $userId)
			->setSelect(['ID'])
			->fetch()
		;

		return $mailbox ? (int)$mailbox['ID'] : null;
	}

	/**
	 * Identifier of the oldest active mailbox holding the address, used to arbitrate concurrent connections.
	 */
	public function findEarliestActiveMailboxId(string $email, ?int $excludeMailboxId = null): ?int
	{
		$normalizedEmail = $this->normalizeEmail($email);
		if ($normalizedEmail === '')
		{
			return null;
		}

		$mailbox = $this->buildEarliestActiveMailboxQuery($normalizedEmail, $excludeMailboxId)
			->setSelect(['ID'])
			->fetch()
		;

		return $mailbox ? (int)$mailbox['ID'] : null;
	}

	public function normalizeEmail(string $email): string
	{
		return $this->emailNormalizer->normalize($email) ?? '';
	}

	private function checkAliasOccupancy(
		string $normalizedEmail,
		int $requestingUserId,
		?int $excludeMailboxId,
	): MailboxEmailOccupancy
	{
		$aliases = array_values(array_filter(
			$this->aliasRepository->findByEmail($normalizedEmail),
			static fn(array $alias): bool => $excludeMailboxId === null
				|| (int)$alias['MAILBOX_ID'] !== $excludeMailboxId,
		));
		if ($aliases === [])
		{
			return MailboxEmailOccupancy::Free;
		}

		$mailboxIds = array_values(array_unique(array_map(
			static fn(array $alias): int => (int)$alias['MAILBOX_ID'],
			$aliases,
		)));
		$mailboxesById = [];
		foreach (array_chunk($mailboxIds, 100) as $mailboxIdChunk)
		{
			$mailboxes = MailboxTable::getList([
				'select' => ['ID', 'USER_ID'],
				'filter' => ['@ID' => $mailboxIdChunk],
			])->fetchAll();
			foreach ($mailboxes as $mailbox)
			{
				$mailboxesById[(int)$mailbox['ID']] = $mailbox;
			}
		}

		foreach ($aliases as $alias)
		{
			$mailbox = $mailboxesById[(int)$alias['MAILBOX_ID']] ?? null;
			if ($mailbox === null)
			{
				continue;
			}
			return (int)$mailbox['USER_ID'] === $requestingUserId
				? MailboxEmailOccupancy::OccupiedByRequester
				: MailboxEmailOccupancy::OccupiedByOther
			;
		}

		return MailboxEmailOccupancy::OccupiedByOther;
	}

	private function buildEarliestActiveMailboxQuery(string $normalizedEmail, ?int $excludeMailboxId): Query
	{
		$query = MailboxTable::query()
			->registerRuntimeField(new ExpressionField('EMAIL_LOWER', 'LOWER(%s)', 'EMAIL'))
			->where('EMAIL_LOWER', $normalizedEmail)
			->where('ACTIVE', MailboxStatus::Active->value)
			->setOrder(['ID' => 'ASC'])
			->setLimit(1)
		;

		if ($excludeMailboxId !== null)
		{
			$query->where('ID', '!=', $excludeMailboxId);
		}

		return $query;
	}
}
