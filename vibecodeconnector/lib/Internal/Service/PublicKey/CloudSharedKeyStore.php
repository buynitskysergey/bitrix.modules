<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\PublicKey;

use Bitrix\Vibecodeconnector\Internal\Config\ModuleOptions;

final class CloudSharedKeyStore
{
	private const OPTION_NAME = 'auth_public_key';
	private const FETCHED_AT_OPTION_NAME = 'cloud_shared_key_fetched_at';
	private const FETCHED_HASH_OPTION_NAME = 'cloud_shared_key_fetched_hash';

	public function __construct(private readonly ModuleOptions $options = new ModuleOptions())
	{
	}

	public function get(): string
	{
		return $this->options->get(self::OPTION_NAME);
	}

	public function set(string $pem): void
	{
		$this->options->set(self::OPTION_NAME, $pem);
		$this->options->set(self::FETCHED_AT_OPTION_NAME, (string)time());
		$this->options->set(self::FETCHED_HASH_OPTION_NAME, self::hash($pem));
	}

	/**
	 * When the key currently stored was fetched by the portal itself, 0 if it came from elsewhere.
	 * An update channel writes the option directly and leaves no mark, so the date is kept together
	 * with the hash of the key it belongs to: a replaced or removed key drops the mark with it.
	 */
	public function getFetchedAt(): int
	{
		$pem = $this->get();
		if ($pem === '')
		{
			return 0;
		}

		$markedHash = $this->options->get(self::FETCHED_HASH_OPTION_NAME);
		if ($markedHash !== self::hash($pem))
		{
			return 0;
		}

		return (int)$this->options->get(self::FETCHED_AT_OPTION_NAME, '0');
	}

	private static function hash(string $pem): string
	{
		return hash('sha256', $pem);
	}
}
