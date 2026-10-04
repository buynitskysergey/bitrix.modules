<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Public\Type\CustomTemplate;

final class BindingDescription
{
	public function __construct(
		public readonly string $sceneLabel,
		public readonly string $targetLabel,
	) {}
}
