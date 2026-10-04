<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Endpoint;

use Bitrix\Vibecodeconnector\Internal\Config\ModuleOptions;

final class CloudEndpointProvider
{
	private const OPTION_NAME = 'cloud_shared_endpoint_url';

	public function __construct(
		private readonly BaseEndpointProvider $baseEndpointProvider = new BaseEndpointProvider(),
		private readonly ModuleOptions $options = new ModuleOptions(),
	) {
	}

	public function getCloudUrl(): string
	{
		$value = $this->options->get(self::OPTION_NAME);

		return $value !== '' ? $value : $this->baseEndpointProvider->getDefaultUrl();
	}

	public function setCloudUrl(string $url): void
	{
		$this->options->set(self::OPTION_NAME, $url);
	}

	public function clearCloudUrl(): void
	{
		$this->options->delete(self::OPTION_NAME);
	}
}
