<?php

namespace Bitrix\Rest\V3\Attribute;

#[\Attribute(\Attribute::TARGET_METHOD)]
final class Idempotent extends AbstractAttribute
{
	public function __construct(public readonly ?int $ttl = null)
	{
	}
}
