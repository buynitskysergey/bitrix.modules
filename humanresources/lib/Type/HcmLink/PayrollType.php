<?php

namespace Bitrix\HumanResources\Type\HcmLink;

use Bitrix\HumanResources\Internals\Trait\ValuesTrait;

/**
 * Type of payroll data requested from 1C.
 *
 * SALARY uses the DOCUMENT entity and requires a month and year.
 * VACATION uses the EMPLOYEE entity and does not require a period.
 */
enum PayrollType: int
{
	use ValuesTrait;

	case SALARY = 1;
	case VACATION = 2;

	/**
	 * Returns the HCM Link field entity type for this payroll type.
	 */
	public function getFieldEntityType(): FieldEntityType
	{
		return match ($this)
		{
			self::SALARY => FieldEntityType::DOCUMENT,
			self::VACATION => FieldEntityType::EMPLOYEE,
		};
	}

	/**
	 * Returns whether this payroll type requires a month and year.
	 */
	public function isPeriodRequired(): bool
	{
		return $this === self::SALARY;
	}

	/**
	 * Code of the ready-to-render HCM Link document field for this type (e.g. the
	 * payroll sheet). When set, the request asks only for this field instead of the
	 * whole entity-type field set. null means there is no dedicated document and the
	 * entity-type fields.
	 */
	public function getDocumentFieldCode(): ?string
	{
		return match ($this)
		{
			self::SALARY => 'РасчётныйЛисток',
			self::VACATION => null,
		};
	}
}
