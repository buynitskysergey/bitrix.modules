<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Public\Type\CustomTemplate;

final class FilterSceneOption
{
	public function __construct(
		public readonly string $sceneId,
		public readonly string $sceneLabel,
	)
	{
	}
}
