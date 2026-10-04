<?php

namespace Bitrix\Rest\V3\Attribute;

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class DynamicEnum extends AbstractAttribute
{
	/**
	 * @param class-string<\Bitrix\Rest\V3\Dto\DynamicEnum\DynamicEnumProvider> $provider
	 */
	public function __construct(public readonly string $provider)
	{
	}
}
