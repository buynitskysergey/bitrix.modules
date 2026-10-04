<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\PublicKey;

use Bitrix\Vibecodeconnector\Internal\Config\ModuleOptions;

final class CloudKeySourceSettings
{
	private const OPTION_NAME = 'cloud_shared_key_source';

	public function __construct(private readonly ModuleOptions $options = new ModuleOptions())
	{
	}

	public function getSource(): PublicKeySource
	{
		return PublicKeySource::tryFromOrDefault($this->options->get(self::OPTION_NAME));
	}

	public function setSource(PublicKeySource $source): void
	{
		$this->options->set(self::OPTION_NAME, $source->value);
	}
}
