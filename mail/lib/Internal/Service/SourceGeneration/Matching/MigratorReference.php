<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\SourceGeneration\Matching;

/**
 * The coordinate of the source letter a managed transfer service writes into every copy
 * it makes, as the MIME header carries it:
 *
 *   v1:<base64url(mailbox)>:<base64url(folder path)>:<uidvalidity>:<uid>
 *
 * The path of the folder travels whole, together with the level delimiter of the source
 * server, decoded into UTF-8: the portal keeps a folder under the hash of exactly such a
 * path, and a path reassembled out of segments hashes to something else: `Work<delimiter>2025`
 * could have been `Work/2025` just as well as `Work.2025`.
 *
 * The parsing is strict and silent. Anything the format does not describe means the letter
 * carries no coordinate at all, and such a letter takes the ordinary route of the matching:
 * nothing about it is an error of the import, so nothing is reported. The hashed variant
 * `v1h` of the same coordinate is not accepted either - searching by it would cost a hash
 * of every letter of the history, stored per letter, for a case that may never occur.
 */
final readonly class MigratorReference
{
	private const VERSION = 'v1';

	private const PARTS = 5;

	/** Unsigned 32 bit, as the contract of the transfer service states the two numbers */
	private const MIN_NUMBER = 1;
	private const MAX_NUMBER = 4294967295;

	private function __construct(
		public string $mailbox,
		public string $folderPath,
		public int $uidValidity,
		public int $uid,
	)
	{
	}

	/**
	 * @return self|null Null for anything the format does not describe.
	 */
	public static function parse(string $value): ?self
	{
		$parts = explode(':', $value);

		if (count($parts) !== self::PARTS || $parts[0] !== self::VERSION)
		{
			return null;
		}

		$mailbox = self::decode($parts[1]);
		$folderPath = self::decode($parts[2]);
		$uidValidity = self::number($parts[3]);
		$uid = self::number($parts[4]);

		if ($mailbox === null || $folderPath === null || $uidValidity === 0 || $uid === 0)
		{
			return null;
		}

		return new self($mailbox, $folderPath, $uidValidity, $uid);
	}

	/**
	 * @return string|null Null when the part is not base64url, padding included: the encoding
	 *         exists to keep the delimiter of the path, spaces and quotes out of the value,
	 *         and a value spelled another way is not the one the contract describes.
	 */
	private static function decode(string $part): ?string
	{
		if (preg_match('/^[A-Za-z0-9_-]++$/D', $part) !== 1)
		{
			return null;
		}

		$decoded = base64_decode(strtr($part, '-_', '+/'), true);

		return $decoded === false || $decoded === '' ? null : $decoded;
	}

	/**
	 * @return int The number, or 0 for anything that is not one of the allowed range. The
	 *         length is bounded before the cast, so no value of the header can overflow it.
	 */
	private static function number(string $part): int
	{
		if (preg_match('/^[0-9]{1,10}+$/D', $part) !== 1)
		{
			return 0;
		}

		$number = (int)$part;

		return $number >= self::MIN_NUMBER && $number <= self::MAX_NUMBER ? $number : 0;
	}
}
