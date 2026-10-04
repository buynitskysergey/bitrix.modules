<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Repository\Item;

use Bitrix\Crm\Model\Dynamic\Factory as DynamicTypeFactory;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Main\DI\ServiceLocator;

/**
 * Reads from the per-Type dynamic items table for SmartInvoice / SmartDocument /
 * SmartB2eDocument and custom smart processes (SPA). Looks up the concrete data class
 * via {@see DynamicTypeFactory::getItemDataClass()} from the {@see Type} config row;
 * returns `null` when the Type is missing so the parent's read methods short-circuit.
 *
 * @internal
 */
final class SmartItemRepository extends AbstractItemRepository
{
	public function __construct(private readonly EntityType $entityType)
	{
	}

	protected function getTableClass(): ?string
	{
		$type = Container::getInstance()->getTypeByEntityTypeId($this->entityType->getId());
		if ($type === null)
		{
			return null;
		}

		/** @var DynamicTypeFactory $typeFactory */
		$typeFactory = ServiceLocator::getInstance()->get('crm.type.factory');

		return $typeFactory->getItemDataClass($type);
	}
}
