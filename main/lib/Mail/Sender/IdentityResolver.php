<?php

namespace Bitrix\Main\Mail\Sender;

use Bitrix\Main\Mail\Address;
use Bitrix\Main\Mail\Internal\SenderTable;

/**
 * Resolves a sender identity into a sender record.
 *
 * Priority is fixed: mailbox reference, then sender reference. The deterministic fallback by the
 * message address runs only when the identity carries neither coordinate: an identity that names a
 * sender nobody can send through resolves to nothing rather than to a record of another owner.
 * Nothing here reads the execution environment.
 */
class IdentityResolver
{
	/**
	 * @param Identity|null $identity Sender identity, when the caller knows it.
	 * @param string|null $fromEmail Address of the message, used by the fallback only.
	 * @param bool $isAmbiguous Set to true when several records matched the address.
	 * @return array|null Sender record with all fields, or null when nothing matched.
	 */
	public function resolve(?Identity $identity, ?string $fromEmail, bool &$isAmbiguous = false): ?array
	{
		$isAmbiguous = false;

		if ($identity !== null && ($identity->hasMailboxRef() || $identity->hasSenderRef()))
		{
			return $this->resolveByIdentity($identity);
		}

		return $this->resolveByAddress($fromEmail, $isAmbiguous);
	}

	/**
	 * Resolves the first usable sender record owned by the user for the exact address.
	 */
	public function resolveForOwner(int $ownerId, string $email): ?array
	{
		$email = $this->normalizeEmail($email);
		if ($ownerId <= 0 || $email === null)
		{
			return null;
		}

		$rows = $this->getUsableRows($this->getSendersByOwnerAndEmail($ownerId, $email));

		return $rows[0] ?? null;
	}

	private function resolveByIdentity(Identity $identity): ?array
	{
		if ($identity->hasMailboxRef())
		{
			$rows = $this->getUsableRows(
				$this->getSendersByParent($identity->mailboxModuleId, $identity->mailboxParentId)
			);

			if (!empty($rows))
			{
				return $rows[0];
			}
		}

		if ($identity->hasSenderRef())
		{
			$row = $this->getSenderById($identity->senderId);
			if ($row !== null && $this->isUsable($row))
			{
				return $row;
			}
		}

		return null;
	}

	private function resolveByAddress(?string $fromEmail, bool &$isAmbiguous): ?array
	{
		$email = $this->normalizeEmail($fromEmail);
		if ($email === null)
		{
			return null;
		}

		$rows = $this->getUsableRows($this->getSendersByEmail($email));
		if (empty($rows))
		{
			return null;
		}

		if (count($rows) === 1)
		{
			return $rows[0];
		}

		$isAmbiguous = true;

		foreach ($rows as $row)
		{
			if (!empty($row['IS_PUBLIC']))
			{
				return $row;
			}
		}

		return $rows[0];
	}

	protected function getSendersByParent(string $mailboxModuleId, int $mailboxParentId): array
	{
		return SenderTable::query()
			->setSelect(['*'])
			->where('PARENT_MODULE_ID', $mailboxModuleId)
			->where('PARENT_ID', $mailboxParentId)
			->fetchAll()
		;
	}

	protected function getSenderById(int $senderId): ?array
	{
		return SenderTable::getById($senderId)->fetch() ?: null;
	}

	protected function getSendersByEmail(string $email): array
	{
		return SenderTable::query()
			->setSelect(['*'])
			->where('EMAIL', $email)
			->fetchAll()
		;
	}

	protected function getSendersByOwnerAndEmail(int $ownerId, string $email): array
	{
		return SenderTable::query()
			->setSelect(['*'])
			->where('USER_ID', $ownerId)
			->where('EMAIL', $email)
			->fetchAll()
		;
	}

	/**
	 * Usable records ordered by ID ascending, so that the choice never depends on creation order.
	 */
	private function getUsableRows(array $rows): array
	{
		$rows = array_values(
			array_filter($rows, fn(array $row) => $this->isUsable($row))
		);

		usort($rows, fn(array $first, array $second) => (int)$first['ID'] <=> (int)$second['ID']);

		return $rows;
	}

	private function isUsable(array $row): bool
	{
		return
			!empty($row['IS_CONFIRMED'])
			&& !empty($row['OPTIONS']['smtp']['server'])
			&& empty($row['OPTIONS']['smtp']['encrypted'])
		;
	}

	private function normalizeEmail(?string $email): ?string
	{
		if ($email === null || $email === '')
		{
			return null;
		}

		$address = new Address($email);

		return $address->validate() ? $address->getEmail() : null;
	}
}
