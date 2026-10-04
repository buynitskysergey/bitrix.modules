<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Public\Dto\CustomTemplate;

use Bitrix\Main\Type\DateTime;

final class CustomTemplateDetails
{
	public function __construct(
		public readonly int $id,
		public readonly string $zoneId,
		public readonly string $sceneId,
		public readonly string $targetId,
		public readonly string $title,
		public readonly string $body,
		public readonly string $sceneLabel,
		public readonly string $targetLabel,
		public readonly DateTime $dateCreate,
		public readonly int $authorId,
		public readonly ?DateTime $dateModify,
		public readonly ?int $modifiedBy,
	) {}
}
