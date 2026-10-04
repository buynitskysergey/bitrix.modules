<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Infrastructure\Rest\Dto\Mapping;

use Bitrix\BizprocDesigner\Infrastructure\Rest\Dto\AgentBlockDto;
use Bitrix\BizprocDesigner\Infrastructure\Rest\Dto\AgentSettingDto;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentBlock;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentSettingCollection;
use Bitrix\Rest\V3\Dto\DtoCollection;

final class AgentBlockMapper extends AbstractAgentMapper
{
	/**
	 * @param list<AgentBlock> $items
	 */
	public function mapCollection(array $items, array $fields = []): DtoCollection
	{
		$collection = new DtoCollection(AgentBlockDto::class);
		foreach ($items as $item)
		{
			$collection->add($item instanceof AgentBlock ? $this->mapBlock($item, $fields) : new AgentBlockDto());
		}

		return $collection;
	}

	/**
	 * The domain entity stays the source of the payload shape: a field is transferred only when the block
	 * emits it, so the conditional emission of rules, returnProperties and the frame overlay is not
	 * duplicated here.
	 */
	private function mapBlock(AgentBlock $block, array $fields): AgentBlockDto
	{
		$dto = new AgentBlockDto();
		foreach ($block->toArray() as $propertyName => $value)
		{
			if (!$this->isRequested($propertyName, $fields))
			{
				continue;
			}

			if ($propertyName === 'settings')
			{
				$dto->settings = $this->mapSettings(
					$block->settings,
					$this->nestedFields($propertyName, $fields),
				);

				continue;
			}

			$dto->{$propertyName} = $value;
		}

		return $dto;
	}

	/**
	 * A setting is a resource of its own, so a select reaching into it narrows the setting rather than
	 * the block carrying it.
	 */
	private function mapSettings(AgentSettingCollection $settings, array $fields): DtoCollection
	{
		$collection = new DtoCollection(AgentSettingDto::class);
		foreach ($settings as $setting)
		{
			$settingDto = new AgentSettingDto();
			foreach ($setting->toArray() as $propertyName => $value)
			{
				if ($this->isRequested($propertyName, $fields))
				{
					$settingDto->{$propertyName} = $value;
				}
			}

			$collection->add($settingDto);
		}

		return $collection;
	}
}
