<?php

namespace Bitrix\Rest\V3\Dto\DynamicEnum;

interface DynamicEnumProvider
{
	public function getType(): DynamicEnumType;

	/**
	 * @return list<string|int>
	 */
	public function getValues(): array;
}
