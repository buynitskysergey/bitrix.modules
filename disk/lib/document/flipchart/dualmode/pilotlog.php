<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Flipchart\DualMode;

use Bitrix\Disk\Internal\Service\Logger\LoggerFactory;
use Bitrix\Main\Application;
use Bitrix\Main\SystemException;

/**
 * The one way the dual mode reports a refusal.
 *
 * Every refusal of the dual mode is fail-closed, and the message of the call site names an entry point
 * and an object id only in the log. The flipchart.pilot channel, however, writes nothing until a portal
 * enables it in .settings.php, so a channel-only record makes the whole feature silent on every portal
 * that has not been configured for it. The core error log is therefore written unconditionally: whether
 * the channel is on is private to LoggerFactory, and a wrong guess about it brings the silence back.
 */
final class PilotLog
{
	private const CHANNEL = 'flipchart.pilot';

	public static function error(string $message, array $context = []): void
	{
		LoggerFactory::create(self::CHANNEL)->error($message, $context);

		Application::getInstance()->getExceptionHandler()->writeToLog(
			new SystemException(self::CHANNEL . ': ' . self::interpolate($message, $context)),
		);
	}

	/**
	 * The core log takes a message and no context, so the placeholders are filled here: without the
	 * object id and the entry point the record says only that something somewhere was refused.
	 */
	private static function interpolate(string $message, array $context): string
	{
		$replacements = [];
		foreach ($context as $key => $value)
		{
			if ($value === null || is_scalar($value) || $value instanceof \Stringable)
			{
				$replacements['{' . $key . '}'] = (string)$value;
			}
		}

		return strtr($message, $replacements);
	}
}
