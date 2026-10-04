<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Public\Dto\CustomTemplate;

use Bitrix\Main\Type\DateTime;

final class CustomTemplateListItem
{
	public function __construct(
		public readonly int $id,
		public readonly string $title,
		public readonly string $body,
		public readonly string $bodyPreview,
		public readonly string $scene,
		public readonly string $targetId,
		public readonly string $sceneLabel,
		public readonly string $targetLabel,
		public readonly int $authorId,
		public readonly DateTime $dateCreate,
		public readonly ?int $modifiedBy,
		public readonly ?DateTime $dateModify,
	) {}
}
