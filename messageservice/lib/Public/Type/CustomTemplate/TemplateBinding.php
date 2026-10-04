<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Public\Type\CustomTemplate;

use Bitrix\Main\Validation\Rule\NotEmpty;

final class TemplateBinding
{
	public function __construct(
		#[NotEmpty]
		public readonly string $zone,
		#[NotEmpty]
		public readonly string $scene,
		#[NotEmpty]
		public readonly string $targetId,
	) {}
}
