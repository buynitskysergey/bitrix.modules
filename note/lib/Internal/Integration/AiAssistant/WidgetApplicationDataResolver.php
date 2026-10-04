<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Integration\AiAssistant;

use Bitrix\Im\V2\Application\Config;
use Bitrix\Main\Loader;
use Throwable;

/**
 * Application data the messenger widget needs to boot. A thin wrapper over im: the shape is owned
 * by \Bitrix\Im\V2\Application\Config and passed through untouched. Ported from
 * landing/lib/Integration/AiAssistant/WidgetApplicationDataResolver.php.
 *
 * An empty array means "im refused", not "nothing to configure" — the caller must treat it as a
 * failure rather than mount the widget without application data.
 */
class WidgetApplicationDataResolver
{
	public function resolve(): array
	{
		if (!Loader::includeModule('im'))
		{
			return [];
		}

		try
		{
			// No argument: Config falls back to the current context on its own.
			return (new Config())->jsonSerialize();
		}
		catch (Throwable)
		{
			return [];
		}
	}
}
