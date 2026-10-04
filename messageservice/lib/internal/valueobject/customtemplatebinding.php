<?php

namespace Bitrix\MessageService\Internal\ValueObject;

final class CustomTemplateBinding
{
	public function __construct(
		public readonly string $zone,
		public readonly string $scene,
		public readonly string $targetId,
	)
	{
	}
}
