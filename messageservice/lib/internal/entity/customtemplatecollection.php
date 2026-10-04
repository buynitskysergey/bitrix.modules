<?php

namespace Bitrix\MessageService\Internal\Entity;

use Bitrix\Main\Entity\EntityCollection;

/**
 * @method \ArrayIterator<int, CustomTemplate> getIterator()
 */
final class CustomTemplateCollection extends EntityCollection
{
	protected static function getEntityClass(): string
	{
		return CustomTemplate::class;
	}
}
