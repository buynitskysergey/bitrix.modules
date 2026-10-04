<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public;

/**
 * CRM Item entity type (Lead, Deal, Contact, Company, Quote, SmartInvoice, SmartDocument, SmartB2eDocument, SmartProcess).
 * Used in Commands, Providers, ItemFactory.
 *
 * Extends OwnerType — can be passed where OwnerType is expected (timeline, permissions, URL).
 */
final class EntityType extends OwnerType
{
	public function __construct(int $id)
	{
		if (!self::isValid($id))
		{
			throw new \Bitrix\Main\ArgumentException("Not a valid Item entity type: {$id}");
		}

		parent::__construct($id);
	}

	/**
	 * Whether the given numeric type ID corresponds to a CRM Item entity that EntityType can wrap:
	 * a static Item entity (Lead, Deal, Contact, Company, Quote, SmartInvoice/Document/B2eDocument)
	 * or a smart process (SPA) ID in valid range.
	 *
	 * Prefer this over try/catch around `new EntityType(...)` / `EntityType::fromId(...)` when the caller
	 * has a non-fatal fallback path for "not an Item type".
	 */
	public static function isValid(int $entityTypeId): bool
	{
		$staticItemTypes = [
			self::LEAD, self::DEAL, self::CONTACT, self::COMPANY, self::QUOTE,
			self::SMART_INVOICE, self::SMART_DOCUMENT, self::SMART_B2E_DOCUMENT,
		];

		return in_array($entityTypeId, $staticItemTypes, true) || self::isPossibleSmartProcessTypeId($entityTypeId);
	}

	public static function lead(): self
	{
		return new self(self::LEAD);
	}

	public static function deal(): self
	{
		return new self(self::DEAL);
	}

	public static function contact(): self
	{
		return new self(self::CONTACT);
	}

	public static function company(): self
	{
		return new self(self::COMPANY);
	}

	public static function quote(): self
	{
		return new self(self::QUOTE);
	}

	public static function smartInvoice(): self
	{
		return new self(self::SMART_INVOICE);
	}

	public static function smartDocument(): self
	{
		return new self(self::SMART_DOCUMENT);
	}

	public static function smartB2eDocument(): self
	{
		return new self(self::SMART_B2E_DOCUMENT);
	}

	/**
	 * Creates type for a custom smart process (SPA).
	 * Validates that ID is in valid range (128-191 or 1030+ even).
	 */
	public static function smartProcess(int $entityTypeId): self
	{
		if (!self::isPossibleSmartProcessTypeId($entityTypeId))
		{
			throw new \Bitrix\Main\ArgumentException("Not a smart process type: {$entityTypeId}");
		}

		return new self($entityTypeId);
	}

	public static function fromId(int $entityTypeId): static
	{
		return new self($entityTypeId);
	}

	/**
	 * Inherited from OwnerType. Preserves the "null on unconstructable" contract by catching
	 * the validation exception that the EntityType constructor throws for non-Item types
	 * (e.g. 'INVOICE', 'ORDER').
	 */
	public static function fromName(string $name): ?static
	{
		try
		{
			return parent::fromName($name);
		}
		catch (\Bitrix\Main\ArgumentException)
		{
			return null;
		}
	}

	public static function fromCode(string $code): ?static
	{
		try
		{
			return parent::fromCode($code);
		}
		catch (\Bitrix\Main\ArgumentException)
		{
			return null;
		}
	}

	/**
	 * Whether this is a custom smart process (SPA, formerly "dynamic").
	 * True for ranges 128-191 and 1030+ (even).
	 */
	public function isSmartProcess(): bool
	{
		return self::isPossibleSmartProcessTypeId($this->getId());
	}

	/**
	 * Whether this type is based on the smart process API.
	 * True for SmartInvoice, SmartDocument, SmartB2eDocument and all SmartProcess types.
	 * Analog of CCrmOwnerType::isUseDynamicTypeBasedApproach().
	 */
	public function isSmartProcessBasedApproach(): bool
	{
		return $this->isSmartProcessBasedStaticEntity() || $this->isSmartProcess();
	}

	/**
	 * Static entity based on smart process API (not SPA itself, but uses the same engine).
	 * SmartInvoice, SmartDocument, SmartB2eDocument.
	 */
	public function isSmartProcessBasedStaticEntity(): bool
	{
		return in_array($this->getId(), self::getSmartProcessBasedStaticEntityTypeIds(), true);
	}

	/**
	 * Returns type IDs of static entities based on smart process API.
	 * Analog of CCrmOwnerType::getDynamicTypeBasedStaticEntityTypeIds().
	 *
	 * @return int[]
	 * @internal
	 */
	public static function getSmartProcessBasedStaticEntityTypeIds(): array
	{
		return [
			self::SMART_INVOICE,
			self::SMART_DOCUMENT,
			self::SMART_B2E_DOCUMENT,
		];
	}
}
