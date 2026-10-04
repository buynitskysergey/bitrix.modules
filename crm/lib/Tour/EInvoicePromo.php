<?php

namespace Bitrix\Crm\Tour;

use Bitrix\Crm\Integration\Rest\EInvoiceApp\Availability;
use CUserOptions;

class EInvoicePromo extends Base
{
	use Availability;

	private const OPTION_NAME_SHOW_TIME = 'einvoice_promo_show_time';
	private const OPTION_NAME_SHOW_COUNT = 'einvoice_promo_show_count';
	private const MAX_USER_SEEN_COUNT = 3;

	private array $analytics = [];

	public function build(array $analytics = []): string
	{
		$this->analytics = $analytics;

		return parent::build();
	}

	protected function canShow(): bool
	{
		if ($this->getNumberOfViews() >= self::MAX_USER_SEEN_COUNT)
		{
			return false;
		}

		$showTime = (int)CUserOptions::GetOption(
			$this->getOptionCategory(),
			self::OPTION_NAME_SHOW_TIME,
		);
		if ($showTime !== 0 && $showTime >= time())
		{
			return false;
		}

		return
			$this->isEInvoiceAvailable()
			&& !$this->isHasInstalledApps()
			&& \CRestUtil::canInstallApplication()
		;
	}

	protected function getComponentTemplate(): string
	{
		return 'einvoice_promo';
	}

	protected function getOptions(): array
	{
		return [
			'numberOfViews' => $this->getNumberOfViews(),
			'optionCategory' => $this->getOptionCategory(),
			'optionNameShowCount' => self::OPTION_NAME_SHOW_COUNT,
			'optionNameShowTime' => self::OPTION_NAME_SHOW_TIME,
			'analytics' => [
				'c_section' => $this->analytics['c_section'] ?? null,
				'c_sub_section' => $this->analytics['c_sub_section'] ?? null,
			],
		];
	}

	private function getNumberOfViews(): int
	{
		return (int)CUserOptions::GetOption(
			$this->getOptionCategory(),
			self::OPTION_NAME_SHOW_COUNT,
		);
	}
}
