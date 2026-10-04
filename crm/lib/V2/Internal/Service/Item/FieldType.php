<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Item;

/**
 * Type tag for {@see FieldDescriptor}. Steers V2 ↔ legacy conversion in {@see Mapper\ItemFieldMapper}.
 *
 * Values mirror the historical strings ('int', 'string', ...) so trace output stays familiar
 * and `from()` keeps working if a callsite still has a raw string.
 *
 * @internal
 */
enum FieldType: string
{
	case Int = 'int';
	case String = 'string';
	case Bool = 'bool';
	case Float = 'float';
	case Date = 'date';
	case Datetime = 'datetime';
	case Array = 'array';
	case File = 'file';

	case ProductRowCollection = 'productRowCollection';
	case ContactBindingCollection = 'contactBindingCollection';
	case CompanyBindingCollection = 'companyBindingCollection';
	case MultifieldCollection = 'multifieldCollection';
	case ObserverCollection = 'observerCollection';
	case Utm = 'utm';
}
