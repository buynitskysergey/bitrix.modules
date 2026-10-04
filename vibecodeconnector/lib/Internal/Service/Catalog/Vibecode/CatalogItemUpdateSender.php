<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\Vibecode;

use Bitrix\Vibecodeconnector\Internal\Entity\Catalog\CatalogItem;
use Bitrix\Vibecodeconnector\Internal\Exception\CatalogSyncFailedException;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\Icon\IconUpdateIntent;

interface CatalogItemUpdateSender
{
	/**
	 * @param array{content: string, format: string}|null $preparedIcon required for IconUpdateIntent::Replace
	 * @throws CatalogSyncFailedException on an unsuccessful Vibecode response.
	 */
	public function updateItem(
		CatalogItem $item,
		int $userId,
		IconUpdateIntent $iconIntent = IconUpdateIntent::Keep,
		?array $preparedIcon = null,
	): void;
}
