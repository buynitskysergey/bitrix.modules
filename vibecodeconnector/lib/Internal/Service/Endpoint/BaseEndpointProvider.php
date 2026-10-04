<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Endpoint;

use Bitrix\Vibecodeconnector\Internal\Config\ModuleOptions;

final class BaseEndpointProvider
{
	private ?string $defaultUrl = null;

	public function __construct(private readonly ModuleOptions $options = new ModuleOptions())
	{
	}

	public function getBaseUrl(): string
	{
		$value = $this->options->get('endpoint_base_url');
		if ($value !== '')
		{
			return $value;
		}

		return $this->getDefaultUrl();
	}

	public function getDefaultUrl(): string
	{
		return $this->defaultUrl ??= (new DefaultEndpointResolver())->resolve();
	}

	/**
	 * @return string[]
	 */
	public function getAvailableUrls(): array
	{
		return (new DefaultEndpointResolver())->getAvailableUrls();
	}

	public function setBaseUrl(string $url): void
	{
		$this->options->set('endpoint_base_url', $url);
	}
}
