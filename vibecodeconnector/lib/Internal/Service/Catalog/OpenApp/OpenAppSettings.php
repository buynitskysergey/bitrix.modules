<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Catalog\OpenApp;

use Bitrix\Vibecodeconnector\Internal\Config\ModuleOptions;

final class OpenAppSettings
{
	private const OPTION_OPEN_APP_IN_IFRAME = 'open_app_in_iframe';

	public function __construct(private readonly ModuleOptions $options = new ModuleOptions())
	{
	}

	public function isOpenInIframeEnabled(): bool
	{
		return $this->options->get(self::OPTION_OPEN_APP_IN_IFRAME, 'N') === 'Y';
	}

	public function setOpenInIframeEnabled(bool $value): void
	{
		$this->options->set(self::OPTION_OPEN_APP_IN_IFRAME, $value ? 'Y' : 'N');
	}
}
