<?php

declare(strict_types=1);

namespace Bitrix\Mail\Service\SharedSignature;

use Bitrix\Mail\Integration\Main\SenderProvider;
use Bitrix\Mail\Internals\Service\Mailbox\AddressBindingResolver;
use Bitrix\Mail\Internals\Service\Mailbox\EmailNormalizer;
use Bitrix\Mail\Internals\Service\Mailbox\ResolvedMailboxBinding;
use Bitrix\Mail\MailboxTable;
use Bitrix\Main\Mail\Address;

class SenderIdentityResolver
{
	private readonly AddressBindingResolver $addressBindingResolver;
	private ?EmailNormalizer $emailNormalizer = null;

	public function __construct(?AddressBindingResolver $addressBindingResolver = null)
	{
		$this->addressBindingResolver = $addressBindingResolver ?? new AddressBindingResolver();
	}

	/**
	 * @param string[] $senders
	 * @return array<string, array{
	 *     sender: string,
	 *     email: string,
	 *     name: string,
	 *     mailboxId: int|null,
	 *     currentEmail: string|null,
	 *     isAlias: bool,
	 *     isHistoryAvailable: bool
	 * }>
	 */
	public function resolveForOwner(int $ownerId, array $senders): array
	{
		$identities = $this->parseSenders($senders);
		if ($identities === [] || $ownerId <= 0)
		{
			return $identities;
		}

		$currentMailboxes = $this->filterOwnerMailboxes($this->loadAccessibleMailboxes($ownerId), $ownerId);
		$currentMailboxesByEmail = $this->groupMailboxesByEmail($currentMailboxes);
		$emails = array_values(array_unique(array_filter(array_column($identities, 'email'))));

		try
		{
			$bindingsByEmail = $this->findBindingsForEmails($emails, $ownerId);
			$mailboxIds = $this->collectMailboxIds($bindingsByEmail);
			$resolvedMailboxes = $this->filterOwnerMailboxes(
				$this->loadActiveMailboxesByIds($mailboxIds),
				$ownerId,
			);
		}
		catch (\Throwable)
		{
			return $this->bindCurrentMailboxes($identities, $currentMailboxesByEmail, false);
		}

		$mailboxesById = [];
		foreach ($resolvedMailboxes as $mailbox)
		{
			$mailboxesById[(int)$mailbox['ID']] = $mailbox;
		}

		foreach ($identities as &$identity)
		{
			$bindings = $bindingsByEmail[$identity['email']] ?? [];
			$binding = $this->selectSingleBinding($bindings, $mailboxesById);
			if ($binding !== null)
			{
				$identity['mailboxId'] = $binding->mailboxId;
				$identity['currentEmail'] = $this->normalizeMailboxEmail($mailboxesById[$binding->mailboxId]);
				$identity['isAlias'] = $binding->isAlias;
			}
		}
		unset($identity);

		return $this->suppressIndependentSenderAliases($ownerId, $identities);
	}

	/**
	 * Drops the alias projection of a sender string that is itself an available independent sender:
	 * such a string denotes the address-literal SMTP identity, not the renamed mailbox, so neither
	 * the compatible reading nor the remembered choice may follow the mailbox to its new address.
	 * The same distinction the write path draws through its candidate lookup.
	 */
	private function suppressIndependentSenderAliases(int $ownerId, array $identities): array
	{
		$aliasEmails = [];
		foreach ($identities as $identity)
		{
			if ($identity['isAlias'] && $identity['email'] !== '')
			{
				$aliasEmails[] = $identity['email'];
			}
		}
		if ($aliasEmails === [])
		{
			return $identities;
		}

		try
		{
			$availableSenders = $this->loadAvailableTypedSenders($ownerId, $aliasEmails);
		}
		catch (\Throwable)
		{
			return $identities;
		}

		$independentKeys = [];
		foreach ($availableSenders as $availableSender)
		{
			if (($availableSender['mailboxId'] ?? null) !== null)
			{
				continue;
			}
			$independentKeys[AssignmentResolver::normalizeSenderKey((string)$availableSender['sender'])] = true;
			$independentKeys[AssignmentResolver::normalizeSenderKey((string)$availableSender['email'])] = true;
		}

		foreach ($identities as $key => &$identity)
		{
			if ($identity['isAlias'] && isset($independentKeys[$key]))
			{
				$identity['mailboxId'] = null;
				$identity['currentEmail'] = null;
				$identity['isAlias'] = false;
			}
		}
		unset($identity);

		return $identities;
	}

	protected function loadAvailableTypedSenders(int $ownerId, array $emails): array
	{
		return SenderProvider::getAvailableTypedSenders($ownerId, $emails);
	}

	/**
	 * @param array<int, int> $ownerIdsByMailboxId
	 * @return array<int, string>
	 */
	public function resolveMailboxEmails(array $ownerIdsByMailboxId): array
	{
		$mailboxes = $this->loadActiveMailboxesByIds(array_keys($ownerIdsByMailboxId));
		$result = [];

		foreach ($mailboxes as $mailbox)
		{
			$mailboxId = (int)($mailbox['ID'] ?? 0);
			if (
				$mailboxId > 0
				&& (int)($mailbox['USER_ID'] ?? 0) === ($ownerIdsByMailboxId[$mailboxId] ?? 0)
			)
			{
				$email = $this->normalizeMailboxEmail($mailbox);
				if ($email !== null)
				{
					$result[$mailboxId] = $email;
				}
			}
		}

		return $result;
	}

	/**
	 * @param string[] $senders
	 */
	private function parseSenders(array $senders): array
	{
		$identities = [];
		foreach ($senders as $sender)
		{
			$sender = trim((string)$sender);
			$key = AssignmentResolver::normalizeSenderKey($sender);
			if (isset($identities[$key]))
			{
				continue;
			}

			$address = new Address($sender);
			$isValid = $address->validate();
			$identities[$key] = [
				'sender' => $sender,
				'email' => $isValid ? mb_strtolower($address->getEmail()) : '',
				'name' => $isValid ? trim($address->getName()) : '',
				'mailboxId' => null,
				'currentEmail' => null,
				'isAlias' => false,
				'isHistoryAvailable' => true,
			];
		}

		return $identities;
	}

	/**
	 * @return array<int, array>
	 */
	protected function loadAccessibleMailboxes(int $ownerId): array
	{
		return array_values(MailboxTable::getUserMailboxes($ownerId));
	}

	/**
	 * @param string[] $emails
	 * @return array<string, ResolvedMailboxBinding[]>
	 */
	protected function findBindingsForEmails(array $emails, int $ownerId): array
	{
		return $this->addressBindingResolver->findBindingsForEmails($emails, $ownerId);
	}

	/**
	 * @param int[] $mailboxIds
	 * @return array<int, array>
	 */
	protected function loadActiveMailboxesByIds(array $mailboxIds): array
	{
		return $this->addressBindingResolver->findActiveMailboxesByIds($mailboxIds);
	}

	private function filterOwnerMailboxes(array $mailboxes, int $ownerId): array
	{
		return array_values(array_filter(
			$mailboxes,
			static fn(array $mailbox): bool =>
				(int)($mailbox['USER_ID'] ?? 0) === $ownerId
				&& ($mailbox['ACTIVE'] ?? 'Y') === 'Y',
		));
	}

	private function groupMailboxesByEmail(array $mailboxes): array
	{
		$result = [];
		foreach ($mailboxes as $mailbox)
		{
			$email = $this->normalizeMailboxEmail($mailbox);
			if ($email !== null)
			{
				$result[$email][] = $mailbox;
			}
		}

		return $result;
	}

	/**
	 * @param array<string, ResolvedMailboxBinding[]> $bindingsByEmail
	 * @return int[]
	 */
	private function collectMailboxIds(array $bindingsByEmail): array
	{
		$mailboxIds = [];
		foreach ($bindingsByEmail as $bindings)
		{
			foreach ($bindings as $binding)
			{
				$mailboxIds[$binding->mailboxId] = true;
			}
		}

		return array_keys($mailboxIds);
	}

	/**
	 * @param ResolvedMailboxBinding[] $bindings
	 * @param array<int, array> $mailboxesById
	 */
	private function selectSingleBinding(array $bindings, array $mailboxesById): ?ResolvedMailboxBinding
	{
		$matches = [];
		foreach ($bindings as $binding)
		{
			if (isset($mailboxesById[$binding->mailboxId]))
			{
				$matches[$binding->mailboxId] = $binding;
			}
		}

		return count($matches) === 1 ? reset($matches) : null;
	}

	private function bindCurrentMailboxes(array $identities, array $mailboxesByEmail, bool $historyAvailable): array
	{
		foreach ($identities as &$identity)
		{
			$identity['isHistoryAvailable'] = $historyAvailable;
			$mailboxes = $mailboxesByEmail[$identity['email']] ?? [];
			if (count($mailboxes) === 1)
			{
				$mailbox = reset($mailboxes);
				$identity['mailboxId'] = (int)$mailbox['ID'];
				$identity['currentEmail'] = $this->normalizeMailboxEmail($mailbox);
			}
		}
		unset($identity);

		return $identities;
	}

	private function normalizeMailboxEmail(array $mailbox): ?string
	{
		$this->emailNormalizer ??= new EmailNormalizer();

		return $this->emailNormalizer->normalizeMailbox($mailbox);
	}
}
