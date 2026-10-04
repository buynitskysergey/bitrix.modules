<?php

declare(strict_types=1);

namespace Bitrix\Crm\Activity\Email\Outgoing;

use Bitrix\Crm\FieldMultiTable;
use Bitrix\Crm\Item;
use Bitrix\Crm\Service\Container;
use CCrmFieldMulti;
use CCrmOwnerType;

final class RecipientResolver
{
	/**
	 * @param list<array{OWNER_TYPE_ID:int, OWNER_ID:int}> $bindings
	 * @param list<string> $to
	 * @param list<string> $cc
	 * @param list<string> $bcc
	 * @return list<array{TYPE:string, VALUE:string, ENTITY_TYPE_ID:int, ENTITY_ID:int}>
	 */
	public function build(
		int $fallbackEntityTypeId,
		int $fallbackEntityId,
		array $bindings,
		array $to,
		array $cc,
		array $bcc,
	): array
	{
		$emails = array_values(array_unique([...$to, ...$cc, ...$bcc]));
		$resolved = $this->resolveRecipientOwners($fallbackEntityTypeId, $fallbackEntityId, $bindings, $emails);

		return $this->buildFromResolvedOwners($fallbackEntityTypeId, $fallbackEntityId, $to, $cc, $bcc, $resolved);
	}

	/**
	 * @param list<string> $to
	 * @param list<string> $cc
	 * @param list<string> $bcc
	 * @param array<string, array{ENTITY_TYPE_ID:int, ENTITY_ID:int}> $resolvedByEmail
	 * @return list<array{TYPE:string, VALUE:string, ENTITY_TYPE_ID:int, ENTITY_ID:int}>
	 */
	public function buildFromResolvedOwners(
		int $fallbackEntityTypeId,
		int $fallbackEntityId,
		array $to,
		array $cc,
		array $bcc,
		array $resolvedByEmail,
	): array
	{
		$communications = [];
		$seen = [];
		foreach ([$to, $cc, $bcc] as $emails)
		{
			foreach ($emails as $email)
			{
				if (!is_string($email) || trim($email) === '')
				{
					continue;
				}

				$value = mb_strtolower(trim($email));
				if (isset($seen[$value]))
				{
					continue;
				}
				$seen[$value] = true;

				$owner = $resolvedByEmail[$value] ?? [
					'ENTITY_TYPE_ID' => $fallbackEntityTypeId,
					'ENTITY_ID' => $fallbackEntityId,
				];

				$communications[] = [
					'TYPE' => 'EMAIL',
					'VALUE' => $value,
					'ENTITY_TYPE_ID' => (int)$owner['ENTITY_TYPE_ID'],
					'ENTITY_ID' => (int)$owner['ENTITY_ID'],
				];
			}
		}

		return $communications;
	}

	/**
	 * @param list<array{OWNER_TYPE_ID:int, OWNER_ID:int}> $bindings
	 * @param list<string> $emails
	 * @return array<string, array{ENTITY_TYPE_ID:int, ENTITY_ID:int}>
	 */
	private function resolveRecipientOwners(int $mainOwnerTypeId, int $mainOwnerId, array $bindings, array $emails): array
	{
		$emailSet = [];
		foreach ($emails as $email)
		{
			$email = mb_strtolower(trim($email));
			if ($email !== '')
			{
				$emailSet[$email] = true;
			}
		}
		if (empty($emailSet))
		{
			return [];
		}

		$resolved = [];
		$baseCandidates = $this->collectBaseCandidateOwners($mainOwnerTypeId, $mainOwnerId, $bindings);
		$this->appendResolvedRecipientOwners($resolved, $emailSet, $this->loadEmailsByOwner($baseCandidates));
		if (count($resolved) === count($emailSet))
		{
			return $resolved;
		}

		$unresolvedEmailSet = array_diff_key($emailSet, $resolved);
		$relatedCandidates = $this->collectRelatedCandidateOwners($baseCandidates);
		$this->appendResolvedRecipientOwners($resolved, $unresolvedEmailSet, $this->loadEmailsByOwner($relatedCandidates));

		return $resolved;
	}

	/**
	 * @param array<string, array{ENTITY_TYPE_ID:int, ENTITY_ID:int}> $resolved
	 * @param array<string, true> $emailSet
	 * @param array<string, array{ENTITY_TYPE_ID:int, ENTITY_ID:int}> $ownersByEmail
	 */
	private function appendResolvedRecipientOwners(array &$resolved, array $emailSet, array $ownersByEmail): void
	{
		foreach ($ownersByEmail as $email => $owner)
		{
			if (isset($emailSet[$email]) && !isset($resolved[$email]))
			{
				$resolved[$email] = $owner;
			}
		}
	}

	/**
	 * @param list<array{OWNER_TYPE_ID:int, OWNER_ID:int}> $bindings
	 * @return list<array{OWNER_TYPE_ID:int, OWNER_ID:int}>
	 */
	private function collectBaseCandidateOwners(int $mainOwnerTypeId, int $mainOwnerId, array $bindings): array
	{
		$result = [];
		$this->appendOwner($result, $mainOwnerTypeId, $mainOwnerId);
		foreach ($bindings as $binding)
		{
			$this->appendOwner($result, (int)$binding['OWNER_TYPE_ID'], (int)$binding['OWNER_ID']);
		}

		return array_values($result);
	}

	/**
	 * @param list<array{OWNER_TYPE_ID:int, OWNER_ID:int}> $baseOwners
	 * @return list<array{OWNER_TYPE_ID:int, OWNER_ID:int}>
	 */
	private function collectRelatedCandidateOwners(array $baseOwners): array
	{
		$result = [];
		foreach ($baseOwners as $owner)
		{
			foreach ($this->collectRelatedClientOwners($owner['OWNER_TYPE_ID'], $owner['OWNER_ID']) as $related)
			{
				$this->appendOwner($result, $related['OWNER_TYPE_ID'], $related['OWNER_ID']);
			}
		}

		$ownersWithCompanies = [...$baseOwners, ...array_values($result)];
		foreach ($ownersWithCompanies as $owner)
		{
			if ($owner['OWNER_TYPE_ID'] !== CCrmOwnerType::Company)
			{
				continue;
			}

			foreach ($this->collectRelatedClientOwners($owner['OWNER_TYPE_ID'], $owner['OWNER_ID']) as $related)
			{
				$this->appendOwner($result, $related['OWNER_TYPE_ID'], $related['OWNER_ID']);
			}
		}

		return array_values($result);
	}

	/**
	 * @return list<array{OWNER_TYPE_ID:int, OWNER_ID:int}>
	 */
	private function collectRelatedClientOwners(int $ownerTypeId, int $ownerId): array
	{
		$factory = Container::getInstance()->getFactory($ownerTypeId);
		$item = $factory?->getItem($ownerId);
		if (!$item instanceof Item)
		{
			return [];
		}

		$result = [];
		if ($item->hasField(Item::FIELD_NAME_COMPANY) && ($company = $item->getCompany()))
		{
			$this->appendOwner($result, CCrmOwnerType::Company, $company->getId());
		}

		if ($item->hasField(Item::FIELD_NAME_CONTACTS))
		{
			foreach ($item->getContactBindings() as $binding)
			{
				$this->appendOwner($result, CCrmOwnerType::Contact, (int)$binding['CONTACT_ID']);
			}
		}

		return array_values($result);
	}

	/**
	 * @param array<string, array{OWNER_TYPE_ID:int, OWNER_ID:int}> $owners
	 */
	private function appendOwner(array &$owners, int $ownerTypeId, int $ownerId): void
	{
		if (!in_array($ownerTypeId, [CCrmOwnerType::Lead, CCrmOwnerType::Deal, CCrmOwnerType::Contact, CCrmOwnerType::Company], true) || $ownerId <= 0)
		{
			return;
		}

		$owners[$ownerTypeId . '_' . $ownerId] = [
			'OWNER_TYPE_ID' => $ownerTypeId,
			'OWNER_ID' => $ownerId,
		];
	}

	/**
	 * @param list<array{OWNER_TYPE_ID:int, OWNER_ID:int}> $owners
	 * @return array<string, array{ENTITY_TYPE_ID:int, ENTITY_ID:int}>
	 */
	private function loadEmailsByOwner(array $owners): array
	{
		$idsByTypeName = [];
		$priorityByOwnerKey = [];
		foreach ($owners as $priority => $owner)
		{
			$typeName = CCrmOwnerType::ResolveName($owner['OWNER_TYPE_ID']);
			if ($typeName === '')
			{
				continue;
			}

			$idsByTypeName[$typeName][] = $owner['OWNER_ID'];
			$priorityByOwnerKey[$typeName . '_' . $owner['OWNER_ID']] = $priority;
		}

		$result = [];
		$resultPriority = [];
		foreach ($idsByTypeName as $typeName => $ids)
		{
			$rows =
				FieldMultiTable::query()
					->setSelect(['ENTITY_ID', 'ELEMENT_ID', 'VALUE'])
					->where('ENTITY_ID', $typeName)
					->where('TYPE_ID', CCrmFieldMulti::EMAIL)
					->whereIn('ELEMENT_ID', array_values(array_unique($ids)))
					->exec()
			;

			while ($row = $rows->fetch())
			{
				$email = mb_strtolower(trim((string)$row['VALUE']));
				if ($email === '')
				{
					continue;
				}

				$ownerKey = $row['ENTITY_ID'] . '_' . (int)$row['ELEMENT_ID'];
				$priority = $priorityByOwnerKey[$ownerKey] ?? PHP_INT_MAX;
				if (isset($resultPriority[$email]) && $resultPriority[$email] <= $priority)
				{
					continue;
				}

				$resultPriority[$email] = $priority;
				$result[$email] = [
					'ENTITY_TYPE_ID' => CCrmOwnerType::ResolveID((string)$row['ENTITY_ID']),
					'ENTITY_ID' => (int)$row['ELEMENT_ID'],
				];
			}
		}

		return $result;
	}
}
