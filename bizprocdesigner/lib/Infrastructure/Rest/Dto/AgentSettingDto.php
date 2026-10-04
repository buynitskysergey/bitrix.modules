<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Dto;

use Bitrix\Rest\V3\Attribute\Editable;
use Bitrix\Rest\V3\Dto\Dto;

/**
 * Single activity setting of a graph block (DTO-01).
 *
 * Property order mirrors AgentSetting::toArray().
 */
class AgentSettingDto extends Dto
{
	#[Editable(['add'])]
	public string $name;

	/**
	 * The domain union is carried as is: the core maps a ReflectionUnionType to 'mixed' and
	 * FieldsConverter leaves a 'mixed' value unconverted, so a string stays a string and an array an array.
	 *
	 * Nullable so that a submitted null reaches the graph validator as an addressed refusal. Without it the
	 * core takes the TypeError path of FieldsStructure::fillDto(), which builds its message through
	 * ReflectionProperty::getType()?->getName() - a method a ReflectionUnionType does not have - and the
	 * refusal degrades into a 500.
	 */
	#[Editable(['add'])]
	public string|array|null $value;
}
