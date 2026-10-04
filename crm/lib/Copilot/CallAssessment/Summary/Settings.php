<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\CallAssessment\Summary;

final class Settings
{
	/**
	 * @param int[] $recipientUserIds
	 * @param int[] $scheduleWeekdays ISO 1..7
	 * @param array<string, array{enabled: bool, threshold: int}> $situations
	 */
	public function __construct(
		public readonly bool $isEnabled = false,
		public readonly array $recipientUserIds = [],
		public readonly array $scheduleWeekdays = [],
		public readonly array $situations = [],
		public readonly bool $sendSelfDigest = false,
	) {}

	public static function createDefault(): self
	{
		return new self(
			scheduleWeekdays: [Weekday::Monday->value],
			situations: self::defaultSituations(),
		);
	}

	public static function fromArray(array $data): self
	{
		return new self(
			isEnabled: self::toBool($data['isEnabled'] ?? false),
			recipientUserIds: self::sanitizeRecipientUserIds($data['recipientUserIds'] ?? []),
			scheduleWeekdays: array_key_exists('scheduleWeekdays', $data)
				? self::sanitizeScheduleWeekdays($data['scheduleWeekdays'])
				: [Weekday::Monday->value],
			situations: self::sanitizeSituations($data['situations'] ?? []),
			sendSelfDigest: self::toBool($data['sendSelfDigest'] ?? false),
		);
	}

	private static function toBool(mixed $value): bool
	{
		if (is_string($value))
		{
			return !in_array(strtolower($value), ['', '0', 'false', 'n', 'no'], true);
		}

		return (bool)$value;
	}

	public function toArray(): array
	{
		return [
			'isEnabled' => $this->isEnabled,
			'recipientUserIds' => array_values($this->recipientUserIds),
			'scheduleWeekdays' => array_values($this->scheduleWeekdays),
			'situations' => $this->situations,
			'sendSelfDigest' => $this->sendSelfDigest,
		];
	}

	/**
	 * @param int[] $userIds
	 */
	public function withRecipientUserIds(array $userIds): self
	{
		return new self(
			isEnabled: $this->isEnabled,
			recipientUserIds: self::sanitizeRecipientUserIds($userIds),
			scheduleWeekdays: $this->scheduleWeekdays,
			situations: $this->situations,
			sendSelfDigest: $this->sendSelfDigest,
		);
	}

	/**
	 * @param mixed $input
	 * @return int[]
	 */
	private static function sanitizeRecipientUserIds(mixed $input): array
	{
		if (!is_array($input))
		{
			return [];
		}

		$ids = array_map(static fn($v) => (int)$v, $input);
		$ids = array_filter($ids, static fn(int $v) => $v > 0);

		return array_values(array_unique($ids));
	}

	/**
	 * @param mixed $input
	 * @return int[]
	 */
	private static function sanitizeScheduleWeekdays(mixed $input): array
	{
		if (!is_array($input))
		{
			return [];
		}

		$days = array_map(static fn($v) => (int)$v, $input);
		$days = array_filter($days, static fn(int $v) => Weekday::tryFrom($v) !== null);

		return array_values(array_unique($days));
	}

	/**
	 * @param mixed $input
	 * @return array<string, array{enabled: bool, threshold: int}>
	 */
	private static function sanitizeSituations(mixed $input): array
	{
		$result = self::defaultSituations();
		if (!is_array($input))
		{
			return $result;
		}

		foreach (Situation::cases() as $situation)
		{
			$code = $situation->value;
			if (!isset($input[$code]) || !is_array($input[$code]))
			{
				continue;
			}

			$result[$code]['enabled'] = self::toBool($input[$code]['enabled'] ?? false);

			$threshold = (int)($input[$code]['threshold'] ?? $situation->getDefaultThreshold());
			$result[$code]['threshold'] = min(
				$situation->getMaxThreshold(),
				max($situation->getMinThreshold(), $threshold),
			);
		}

		return $result;
	}

	/**
	 * @return array<string, array{enabled: bool, threshold: int}>
	 */
	private static function defaultSituations(): array
	{
		$result = [];
		foreach (Situation::cases() as $situation)
		{
			$result[$situation->value] = [
				'enabled' => false,
				'threshold' => $situation->getDefaultThreshold(),
			];
		}

		return $result;
	}
}
