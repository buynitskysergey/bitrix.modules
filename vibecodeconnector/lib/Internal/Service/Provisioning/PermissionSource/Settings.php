<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Provisioning\PermissionSource;

use Bitrix\Vibecodeconnector\Internal\Config\ModuleOptions;

final class Settings
{
	public const VIBECODE = 'vibecode';
	public const PORTAL = 'portal';

	private const OPTION_NAME = 'permission_source';

	public function __construct(private readonly ModuleOptions $options = new ModuleOptions())
	{
	}

	public function getValue(): ?string
	{
		$raw = $this->options->get(self::OPTION_NAME);

		return $raw === '' ? null : $raw;
	}

	public function setVibecodeSource(bool $isVibecode): void
	{
		$this->options->set(self::OPTION_NAME, $isVibecode ? self::VIBECODE : self::PORTAL);
	}
}
