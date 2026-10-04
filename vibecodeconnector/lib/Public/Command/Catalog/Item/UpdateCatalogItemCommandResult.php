<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Public\Command\Catalog\Item;

use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItem;

final class UpdateCatalogItemCommandResult
{
	public function __construct(
		public readonly CatalogItem $item,
		public readonly ?int $displacedIconFileId = null,
	) {}
}
