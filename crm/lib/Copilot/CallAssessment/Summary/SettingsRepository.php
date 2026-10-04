<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\CallAssessment\Summary;

use Bitrix\Main\ArgumentException;
use Bitrix\Main\Config\Option;
use Bitrix\Main\UserTable;
use Bitrix\Main\Web\Json;

final class SettingsRepository
{
	public const MODULE_ID   = 'crm';
	public const OPTION_NAME = 'call_scoring_v2_summary_settings';

	public function load(): Settings
	{
		$raw = Option::get(self::MODULE_ID, self::OPTION_NAME, '');
		if ($raw === '')
		{
			return Settings::createDefault();
		}

		try
		{
			$data = Json::decode($raw);
		}
		catch (ArgumentException)
		{
			return Settings::createDefault();
		}

		if (!is_array($data))
		{
			return Settings::createDefault();
		}

		return $this->filterActiveRecipients(Settings::fromArray($data));
	}

	/**
	 * Drops fired (ACTIVE='N') and deleted (no b_user row) recipients on read.
	 * The stored option is not rewritten; dead ids disappear on the next save.
	 */
	private function filterActiveRecipients(Settings $settings): Settings
	{
		if ($settings->recipientUserIds === [])
		{
			return $settings;
		}

		$activeIds = UserTable::query()
			->setSelect(['ID'])
			->whereIn('ID', $settings->recipientUserIds)
			->where('ACTIVE', 'Y')
			->fetchAll()
		;

		$activeIds = array_column($activeIds, 'ID');
		$activeIds = array_map('intval', $activeIds);
		$activeIds = array_fill_keys($activeIds, true);

		$filteredIds = array_values(array_filter(
			$settings->recipientUserIds,
			static fn(int $id): bool => isset($activeIds[$id]),
		));

		if ($filteredIds === $settings->recipientUserIds)
		{
			return $settings;
		}

		return $settings->withRecipientUserIds($filteredIds);
	}

	public function save(Settings $settings): void
	{
		Option::set(
			self::MODULE_ID,
			self::OPTION_NAME,
			Json::encode($settings->toArray()),
		);
	}

	public function isInitialized(): bool
	{
		return Option::get(self::MODULE_ID, self::OPTION_NAME, null) !== null;
	}
}
