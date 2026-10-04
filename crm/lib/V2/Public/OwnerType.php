<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public;

/**
 * Base type identifier for any CRM object.
 * Replaces int $entityTypeId / CCrmOwnerType for generic code (timeline, permissions, URL).
 *
 * For Item entities (Lead, Deal, Contact, Company...) use EntityType instead.
 */
class OwnerType
{
	public const UNDEFINED = 0;

	// Item entities (used in EntityType: Commands, Providers, ItemFactory)
	public const LEAD = 1;
	public const DEAL = 2;
	public const CONTACT = 3;
	public const COMPANY = 4;
	public const QUOTE = 7;
	public const SMART_INVOICE = 31;
	public const SMART_DOCUMENT = 36;
	public const SMART_B2E_DOCUMENT = 39;

	// SmartProcess type ranges (custom SPA entities)
	public const SMART_PROCESS_TYPE_START = 128;
	public const SMART_PROCESS_TYPE_END = 192;
	public const UNLIMITED_TYPE_START = 1030;

	// Ecommerce / legacy entities
	public const INVOICE = 5;
	public const ORDER = 14;
	public const ORDER_CHECK = 15;
	public const ORDER_SHIPMENT = 16;
	public const ORDER_PAYMENT = 17;
	public const INVOICE_RECURRING = 27;
	public const CHECK_CORRECTION = 29;
	public const DELIVERY_REQUEST = 30;
	public const STORE_DOCUMENT = 33;
	public const SHIPMENT_DOCUMENT = 34;
	public const AGENT_CONTRACT_DOCUMENT = 38;

	// Related objects
	public const ACTIVITY = 6;
	public const REQUISITE = 8;
	public const BANK_DETAIL = 35;
	public const CALL_LIST = 12;
	public const DEAL_RECURRING = 13;
	public const COPILOT_CALL_ASSESSMENT = 41;

	// System
	public const SYSTEM = 1024;

	// Type names
	public const LEAD_NAME = 'LEAD';
	public const DEAL_NAME = 'DEAL';
	public const CONTACT_NAME = 'CONTACT';
	public const COMPANY_NAME = 'COMPANY';
	public const INVOICE_NAME = 'INVOICE';
	public const ACTIVITY_NAME = 'ACTIVITY';
	public const QUOTE_NAME = 'QUOTE';
	public const REQUISITE_NAME = 'REQUISITE';
	public const CALL_LIST_NAME = 'CALL_LIST';
	public const DEAL_RECURRING_NAME = 'DEAL_RECURRING';
	public const ORDER_NAME = 'ORDER';
	public const ORDER_CHECK_NAME = 'ORDER_CHECK';
	public const ORDER_SHIPMENT_NAME = 'ORDER_SHIPMENT';
	public const ORDER_PAYMENT_NAME = 'ORDER_PAYMENT';
	public const INVOICE_RECURRING_NAME = 'INVOICE_RECURRING';
	public const CHECK_CORRECTION_NAME = 'CHECK_CORRECTION';
	public const DELIVERY_REQUEST_NAME = 'DELIVERY_REQUEST';
	public const SMART_INVOICE_NAME = 'SMART_INVOICE';
	public const STORE_DOCUMENT_NAME = 'STORE_DOCUMENT';
	public const SHIPMENT_DOCUMENT_NAME = 'SHIPMENT_DOCUMENT';
	public const BANK_DETAIL_NAME = 'BANK_DETAIL';
	public const SMART_DOCUMENT_NAME = 'SMART_DOCUMENT';
	public const AGENT_CONTRACT_NAME = 'AGENT_CONTRACT';
	public const SMART_B2E_DOCUMENT_NAME = 'SMART_B2E_DOC';
	public const COPILOT_CALL_ASSESSMENT_NAME = 'COPILOT_CALL_ASSESSMENT';
	public const SYSTEM_NAME = 'SYSTEM';

	// Dynamic name prefix
	public const DYNAMIC_NAME_PREFIX = 'DYNAMIC_';

	// Dynamic code prefix (PascalCase form used in class names)
	public const SMART_PROCESS_CODE_PREFIX = 'SmartProcess';

	/** @var array<int, string> ID → name mapping for static types */
	private const ID_TO_NAME = [
		self::LEAD => self::LEAD_NAME,
		self::DEAL => self::DEAL_NAME,
		self::CONTACT => self::CONTACT_NAME,
		self::COMPANY => self::COMPANY_NAME,
		self::INVOICE => self::INVOICE_NAME,
		self::ACTIVITY => self::ACTIVITY_NAME,
		self::QUOTE => self::QUOTE_NAME,
		self::REQUISITE => self::REQUISITE_NAME,
		self::CALL_LIST => self::CALL_LIST_NAME,
		self::DEAL_RECURRING => self::DEAL_RECURRING_NAME,
		self::ORDER => self::ORDER_NAME,
		self::ORDER_CHECK => self::ORDER_CHECK_NAME,
		self::ORDER_SHIPMENT => self::ORDER_SHIPMENT_NAME,
		self::ORDER_PAYMENT => self::ORDER_PAYMENT_NAME,
		self::INVOICE_RECURRING => self::INVOICE_RECURRING_NAME,
		self::CHECK_CORRECTION => self::CHECK_CORRECTION_NAME,
		self::DELIVERY_REQUEST => self::DELIVERY_REQUEST_NAME,
		self::SMART_INVOICE => self::SMART_INVOICE_NAME,
		self::STORE_DOCUMENT => self::STORE_DOCUMENT_NAME,
		self::SHIPMENT_DOCUMENT => self::SHIPMENT_DOCUMENT_NAME,
		self::BANK_DETAIL => self::BANK_DETAIL_NAME,
		self::SMART_DOCUMENT => self::SMART_DOCUMENT_NAME,
		self::AGENT_CONTRACT_DOCUMENT => self::AGENT_CONTRACT_NAME,
		self::SMART_B2E_DOCUMENT => self::SMART_B2E_DOCUMENT_NAME,
		self::COPILOT_CALL_ASSESSMENT => self::COPILOT_CALL_ASSESSMENT_NAME,
		self::SYSTEM => self::SYSTEM_NAME,
	];

	/** @var array<int, string> ID → PascalCase code mapping for static types */
	private const ID_TO_CODE = [
		self::LEAD => 'Lead',
		self::DEAL => 'Deal',
		self::CONTACT => 'Contact',
		self::COMPANY => 'Company',
		self::INVOICE => 'Invoice',
		self::ACTIVITY => 'Activity',
		self::QUOTE => 'Quote',
		self::REQUISITE => 'Requisite',
		self::CALL_LIST => 'CallList',
		self::DEAL_RECURRING => 'DealRecurring',
		self::ORDER => 'Order',
		self::ORDER_CHECK => 'OrderCheck',
		self::ORDER_SHIPMENT => 'OrderShipment',
		self::ORDER_PAYMENT => 'OrderPayment',
		self::INVOICE_RECURRING => 'InvoiceRecurring',
		self::CHECK_CORRECTION => 'CheckCorrection',
		self::DELIVERY_REQUEST => 'DeliveryRequest',
		self::SMART_INVOICE => 'SmartInvoice',
		self::STORE_DOCUMENT => 'StoreDocument',
		self::SHIPMENT_DOCUMENT => 'ShipmentDocument',
		self::BANK_DETAIL => 'BankDetail',
		self::SMART_DOCUMENT => 'SmartDocument',
		self::AGENT_CONTRACT_DOCUMENT => 'AgentContractDocument',
		self::SMART_B2E_DOCUMENT => 'SmartB2eDocument',
		self::COPILOT_CALL_ASSESSMENT => 'CopilotCallAssessment',
		self::SYSTEM => 'System',
	];

	/** @var array<string, int>|null Lazy-initialized reverse mapping */
	private static ?array $nameToId = null;

	/** @var array<string, int>|null Lazy-initialized reverse mapping (lowercased keys) */
	private static ?array $codeToId = null;

	public function __construct(
		private readonly int $id,
	)
	{
	}

	public static function fromId(int $id): static
	{
		return new static($id);
	}

	/**
	 * Creates OwnerType from string name ('LEAD', 'DEAL', 'DYNAMIC_128'...).
	 * Returns null if name is not recognized.
	 */
	public static function fromName(string $name): ?static
	{
		$name = mb_strtoupper(trim($name));

		if ($name === '')
		{
			return null;
		}

		// Check static mapping
		$map = self::getNameToIdMap();
		if (isset($map[$name]))
		{
			return new static($map[$name]);
		}

		// Check dynamic pattern: DYNAMIC_128
		if (preg_match('/^' . self::DYNAMIC_NAME_PREFIX . '(\d+)$/', $name, $matches))
		{
			return new static((int)$matches[1]);
		}

		return null;
	}

	/**
	 * Creates OwnerType from string code ('Lead', 'Deal', 'SmartProcess128'...).
	 * Returns null if code is not recognized.
	 */
	public static function fromCode(string $code): ?static
	{
		$code = mb_strtolower(trim($code));

		if ($code === '')
		{
			return null;
		}

		// Check static mapping
		$map = self::getCodeToIdMap();
		if (isset($map[$code]))
		{
			return new static($map[$code]);
		}

		// Check dynamic pattern: SmartProcess128
		if (preg_match('/^' . mb_strtolower(self::SMART_PROCESS_CODE_PREFIX) . '(\d+)$/', $code, $matches))
		{
			return new static((int)$matches[1]);
		}

		return null;
	}

	public function getId(): int
	{
		return $this->id;
	}

	/**
	 * String name of the type ('LEAD', 'DEAL', 'DYNAMIC_128'...).
	 *
	 * Returns an empty string for unknown types (asymmetric with {@see getCode()}, which returns null).
	 * This is intentional and must not change to null: the return type is non-nullable `string` and
	 * {@see \CCrmOwnerType::ResolveName()} relies on the empty-string sentinel to fall back to legacy
	 * type resolution. Use {@see fromName()}/{@see fromCode()} when you need explicit "unknown" handling.
	 */
	public function getName(): string
	{
		// Static types
		if (isset(self::ID_TO_NAME[$this->id]))
		{
			return self::ID_TO_NAME[$this->id];
		}

		// Dynamic types
		if (self::isPossibleSmartProcessTypeId($this->id))
		{
			return self::DYNAMIC_NAME_PREFIX . $this->id;
		}

		return '';
	}

	/**
	 * PascalCase code of the type ('Lead', 'Deal', 'SmartProcess128'...).
	 *
	 * Returns null for unknown types (asymmetric with {@see getName()}, which returns an empty string
	 * for backward compatibility with the legacy string contract). null is the correct sentinel here as
	 * there are no callers depending on an empty-string fallback for codes.
	 */
	public function getCode(): ?string
	{
		// Static types
		if (isset(self::ID_TO_CODE[$this->id]))
		{
			return self::ID_TO_CODE[$this->id];
		}

		// Dynamic types
		if (self::isPossibleSmartProcessTypeId($this->id))
		{
			return self::SMART_PROCESS_CODE_PREFIX . $this->id;
		}

		return null;
	}

	public function equals(self $other): bool
	{
		return $this->id === $other->id;
	}

	public static function isPossibleSmartProcessTypeId(int $id): bool
	{
		if ($id >= self::UNLIMITED_TYPE_START)
		{
			return $id % 2 === 0;
		}

		return ($id >= self::SMART_PROCESS_TYPE_START && $id < self::SMART_PROCESS_TYPE_END);
	}

	private static function getNameToIdMap(): array
	{
		if (self::$nameToId === null)
		{
			self::$nameToId = array_flip(self::ID_TO_NAME);
		}

		return self::$nameToId;
	}

	private static function getCodeToIdMap(): array
	{
		if (self::$codeToId === null)
		{
			self::$codeToId = array_flip(array_map('mb_strtolower', self::ID_TO_CODE));
		}

		return self::$codeToId;
	}
}
