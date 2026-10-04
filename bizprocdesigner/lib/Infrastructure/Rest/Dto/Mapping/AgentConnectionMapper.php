<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Dto\Mapping;

use Bitrix\BizprocDesigner\Infrastructure\Rest\Dto\AgentConnectionDto;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentConnection;
use Bitrix\Rest\V3\Dto\DtoCollection;

final class AgentConnectionMapper extends AbstractAgentMapper
{
	/**
	 * @param list<AgentConnection> $items
	 */
	public function mapCollection(array $items, array $fields = []): DtoCollection
	{
		$collection = new DtoCollection(AgentConnectionDto::class);
		foreach ($items as $item)
		{
			$collection->add(
				$item instanceof AgentConnection ? $this->mapConnection($item, $fields) : new AgentConnectionDto(),
			);
		}

		return $collection;
	}

	/**
	 * The domain entity stays the source of the payload shape: a port id is transferred only when the
	 * connection emits it, so the conditional emission is not duplicated here.
	 */
	private function mapConnection(AgentConnection $connection, array $fields): AgentConnectionDto
	{
		$dto = new AgentConnectionDto();
		foreach ($connection->toArray() as $propertyName => $value)
		{
			if (!$this->isRequested($propertyName, $fields))
			{
				continue;
			}

			$dto->{$propertyName} = $value;
		}

		return $dto;
	}
}
