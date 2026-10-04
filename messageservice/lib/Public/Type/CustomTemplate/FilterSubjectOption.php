<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Public\Type\CustomTemplate;

final class FilterSubjectOption
{
	public function __construct(
		public readonly string $targetId,
		public readonly string $targetLabel,
	)
	{
	}
}
