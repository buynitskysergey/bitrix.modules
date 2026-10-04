<?php

namespace Bitrix\Crm\Ads\Pixel\ConversionEventTriggers\Vk;

use Bitrix\Crm\Ads\Pixel\ConversionWrapper;
use Bitrix\Crm\Ads\Pixel\EventBuilders\CrmConversionEventBuilderInterface;
use Bitrix\Crm\Service\Logger\LoggerFactory;
use Bitrix\Main\DI\ServiceLocator;

abstract class BaseTrigger
{
	protected ?ConversionWrapper $conversion = null;

	abstract protected function checkTarget(): bool;

	abstract protected function allowByCooldown(): bool;

	abstract protected function getConversionEventBuilder(): CrmConversionEventBuilderInterface;

	protected static function logHandlerFailure(string $entityType, int $entityId, \Throwable $exception): void
	{
		try
		{
			LoggerFactory::create('Ads.VkadsConversion')->error(
				'VK Ads conversion trigger failed',
				[
					'entityType' => $entityType,
					'entityId' => $entityId,
					'exceptionClass' => $exception::class,
				],
			);
		}
		catch (\Throwable)
		{
		}
	}

	public function __construct()
	{
		$locator = ServiceLocator::getInstance();
		if ($locator->has('crm.service.ads.conversion.vkads'))
		{
			$this->conversion = $locator->get('crm.service.ads.conversion.vkads');
		}
	}

	public function getWrapper(): ?ConversionWrapper
	{
		return $this->conversion;
	}

	public function execute(): void
	{
		if (!$this->checkTarget())
		{
			return;
		}

		$conversion = $this->getWrapper();
		if ($conversion === null || !$conversion->isAvailable())
		{
			return;
		}

		if (!$this->allowByCooldown())
		{
			return;
		}

		$conversion->addEvents($this->getConversionEventBuilder());
	}
}
