<?php

declare(strict_types=1);

namespace Bitrix\Crm\Activity\Email;

use Bitrix\Crm\ActivityTable;
use Bitrix\Main\Mail\Address;

final readonly class MetaParser
{
	private function __construct(private array $meta)
	{
	}

	public static function fromActivityFields(array $fields): self
	{
		$settings = self::normalizeSettings($fields['SETTINGS'] ?? []);

		return self::fromSettings($settings);
	}

	public static function fromSettings(array $settings): self
	{
		$meta = is_array($settings['EMAIL_META'] ?? null) ? $settings['EMAIL_META'] : [];

		return new self($meta);
	}

	public function getFrom(): string
	{
		$from = $this->getFromList();
		if (!empty($from))
		{
			return $from[0];
		}

		$ownerEmails = $this->getOwnerEmails();

		return $ownerEmails[0] ?? '';
	}

	/**
	 * @return list<string>
	 */
	public function getFromList(): array
	{
		return self::parseEmailList($this->getMetaValue('from'));
	}

	/**
	 * @return list<string>
	 */
	public function getOwnerEmails(): array
	{
		return self::parseEmailList($this->getMetaValue('__email'));
	}

	/**
	 * @return list<string>
	 */
	public function getReplyTo(): array
	{
		return self::parseEmailList($this->getMetaValue('replyTo'));
	}

	/**
	 * @return list<string>
	 */
	public function getTo(): array
	{
		return self::parseEmailList($this->getMetaValue('to'));
	}

	/**
	 * @return list<string>
	 */
	public function getCc(): array
	{
		return self::parseEmailList($this->getMetaValue('cc'));
	}

	/**
	 * @return list<string>
	 */
	public function getBcc(): array
	{
		return self::parseEmailList($this->getMetaValue('bcc'));
	}

	/**
	 * @param array|string|null $raw
	 * @return list<string>
	 */
	public static function parseEmailList(array|string|null $raw): array
	{
		$items = is_array($raw) ? $raw : explode(',', (string)$raw);
		$result = [];
		foreach ($items as $item)
		{
			if (!is_string($item))
			{
				continue;
			}

			$address = new Address(trim($item));
			if ($address->validate())
			{
				$email = mb_strtolower((string)$address->getEmail());
				$result[$email] = $email;
			}
		}

		return array_values($result);
	}

	private function getMetaValue(string $key): array|string
	{
		$value = $this->meta[$key] ?? '';

		return is_array($value) || is_string($value) ? $value : '';
	}

	private static function normalizeSettings(mixed $settings): array
	{
		if (is_array($settings))
		{
			return $settings;
		}

		if (!is_string($settings) || !preg_match('/^a:\d+:{/', $settings))
		{
			return [];
		}

		$settings = @ActivityTable::unserializeSettings($settings);

		return is_array($settings) ? $settings : [];
	}
}
