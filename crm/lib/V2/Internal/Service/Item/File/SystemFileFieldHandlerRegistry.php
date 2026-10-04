<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Item\File;

use Bitrix\Crm\V2\Public\EntityType;

final class SystemFileFieldHandlerRegistry
{
	/** @var list<SystemFileFieldHandler> */
	private array $handlers;

	/** @param list<SystemFileFieldHandler>|null $handlers */
	public function __construct(?array $handlers = null)
	{
		$this->handlers = $handlers ?? [new ContactPhotoHandler()];
	}

	public function get(EntityType $entityType, string $fieldName): ?SystemFileFieldHandler
	{
		foreach ($this->handlers as $handler)
		{
			if ($handler->supports($entityType, $fieldName))
			{
				return $handler;
			}
		}

		return null;
	}

	public function getByRestField(EntityType $entityType, string $restFieldName): ?SystemFileFieldHandler
	{
		foreach ($this->handlers as $handler)
		{
			if ($handler->getRestFieldName() === $restFieldName && $handler->supports($entityType, $handler->getFieldName()))
			{
				return $handler;
			}
		}

		return null;
	}
}
