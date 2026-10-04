<?php

namespace Bitrix\Crm\Service\Timeline\AiReportDrawer\Support;

final class SettingsProvider
{
	public const CHOOSE_NEW_SCRIPT = 'choose-new-script';
	public const ANALYTICS = 'analytics';
	public const DELIMITER = 'delimiter';
	public const SHARE_SLIDER = 'share-slider';
	public const HOW_IT_WORKS = 'how-it-works';

	private const ANALYTICS_OPEN_FROM = 'crm_ai_report_drawer';
	private const HOW_IT_WORKS_ARTICLE_CODE = '23240682';
	private const ANALYTICS_LOCK_INFO_HELPER_CODE_BUILDER = 'limit_crm_BI_constructor';
	private const ANALYTICS_LOCK_INFO_HELPER_CODE_MARKET = 'limit_benefit_market_active';

	private ?string $analyticsButtonLockInfoHelperCode = null;

	public function getButton(string $buttonId): ?array
	{
		return match ($buttonId) {
			self::CHOOSE_NEW_SCRIPT => $this->getChooseNewScriptButton(),
			self::ANALYTICS => $this->getAnalyticsButton(),
			self::DELIMITER => $this->getDelimiterButton(),
			self::SHARE_SLIDER => $this->getShareSliderButton(),
			self::HOW_IT_WORKS => $this->getHowItWorksButton(),
			default => null,
		};
	}

	private function getChooseNewScriptButton(): array
	{
		return [
			'id' => self::CHOOSE_NEW_SCRIPT,
		];
	}

	private function getAnalyticsButton(): ?array
	{
		[$lockInfoHelperCode, $dashboardUrl] = $this->getAnalyticsActionData();
		$onclick = $this->prepareAnalyticsOnclick($lockInfoHelperCode, $dashboardUrl);
		if ($onclick === null)
		{
			return null;
		}

		return [
			'id' => self::ANALYTICS,
			'onclick' => $onclick,
		];
	}

	private function getDelimiterButton(): array
	{
		return [
			'id' => self::DELIMITER,
		];
	}

	private function getShareSliderButton(): array
	{
		return [
			'id' => self::SHARE_SLIDER,
		];
	}

	private function getHowItWorksButton(): array
	{
		return [
			'id' => self::HOW_IT_WORKS,
			'onclick' => "window.top.BX?.Helper?.show('redirect=detail&code=" . self::HOW_IT_WORKS_ARTICLE_CODE . "');",
		];
	}

	//region Analytics Button
	private function getAnalyticsActionData(): array
	{
		$lockInfoHelperCode = $this->getAnalyticsLockInfoHelperCode();
		if ($lockInfoHelperCode !== null)
		{
			return [$lockInfoHelperCode, null];
		}

		return [null, $this->getAnalyticsUrl()];
	}

	private function getAnalyticsLockInfoHelperCode(): ?string
	{
		if ($this->analyticsButtonLockInfoHelperCode !== null)
		{
			return $this->analyticsButtonLockInfoHelperCode;
		}

		$this->analyticsButtonLockInfoHelperCode = $this->computeAnalyticsLockInfoHelperCode();

		return $this->analyticsButtonLockInfoHelperCode;
	}

	private function getAnalyticsUrl(): ?string
	{
		if ($this->getAnalyticsLockInfoHelperCode() !== null)
		{
			return null;
		}

		return $this->getAnalyticsDashboardUrl(self::ANALYTICS_OPEN_FROM);
	}

	private function computeAnalyticsLockInfoHelperCode(): ?string
	{
		if (!$this->isAnalyticsFeatureEnabled())
		{
			return self::ANALYTICS_LOCK_INFO_HELPER_CODE_BUILDER;
		}

		if ($this->isAnalyticsLockedByMarketSubscription())
		{
			return self::ANALYTICS_LOCK_INFO_HELPER_CODE_MARKET;
		}

		return null;
	}

	private function isAnalyticsFeatureEnabled(): bool
	{
		// @todo Replace stub with Dashboard::create(self::ANALYTICS_DASHBOARD_APP_ID)?->isFeatureEnabled() check.
		return false;
	}

	private function analyticsDashboardExists(): bool
	{
		// @todo Replace stub with Dashboard::create(self::ANALYTICS_DASHBOARD_APP_ID)?->exists() check.
		return false;
	}

	private function isAnalyticsLockedByMarketSubscription(): bool
	{
		// @todo Replace stub with Dashboard::create(self::ANALYTICS_DASHBOARD_APP_ID)?->isLockedByMarketSubscription() check.
		return false;
	}

	private function getAnalyticsDashboardUrl(string $openFrom): ?string
	{
		// @todo Replace stub with Dashboard::create(self::ANALYTICS_DASHBOARD_APP_ID)?->getEmbeddedUrl($openFrom).
		return null;
	}
	//endregion

	private function prepareAnalyticsOnclick(?string $lockInfoHelperCode, ?string $dashboardUrl): ?string
	{
		if (is_string($lockInfoHelperCode) && $lockInfoHelperCode !== '')
		{
			return "top.BX.UI.InfoHelper.show('" . \CUtil::JSEscape($lockInfoHelperCode) . "');";
		}

		if (is_string($dashboardUrl) && $dashboardUrl !== '')
		{
			return "window.open('" . \CUtil::JSEscape($dashboardUrl) . "', '_blank');";
		}

		return null;
	}
}
