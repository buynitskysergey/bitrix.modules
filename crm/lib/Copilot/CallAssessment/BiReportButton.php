<?php

namespace Bitrix\Crm\Copilot\CallAssessment;

use Bitrix\Crm\Integration\BiConnector\Dashboard;
use Bitrix\Crm\Traits\Singleton;
use Bitrix\UI\Buttons\JsCode;

final class BiReportButton
{
	use Singleton;

	private const DASHBOARD_APP_ID = 'bitrix.bic_telephony';
	private const LOCK_INFO_HELPER_CODE_BUILDER = 'limit_crm_BI_constructor';
	private const LOCK_INFO_HELPER_CODE_MARKET = 'limit_benefit_market_active';

	private ?Dashboard $dashboard = null;
	private ?bool $renderable = null;
	private ?string $lockInfoHelperCode = null;

	public function shouldRenderButton(): bool
	{
		return $this->renderable ??= $this->computeRenderable();
	}

	private function computeRenderable(): bool
	{
		$dashboard = $this->getDashboard();
		if ($dashboard === null)
		{
			return false;
		}

		if (!$dashboard->isFeatureEnabled())
		{
			return true;
		}

		if (!$dashboard->exists())
		{
			return false;
		}

		return $dashboard->isViewableByCurrentUser();
	}

	public function getLockInfoHelperCode(): ?string
	{
		if ($this->lockInfoHelperCode)
		{
			return $this->lockInfoHelperCode;
		}

		$this->lockInfoHelperCode = $this->computeLockInfoHelperCode();

		return $this->lockInfoHelperCode;
	}

	private function computeLockInfoHelperCode(): ?string
	{
		if (!$this->shouldRenderButton())
		{
			return null;
		}

		$dashboard = $this->getDashboard();
		if (!$dashboard->isFeatureEnabled())
		{
			return self::LOCK_INFO_HELPER_CODE_BUILDER;
		}

		if ($dashboard->isLockedByMarketSubscription())
		{
			return self::LOCK_INFO_HELPER_CODE_MARKET;
		}

		return null;
	}

	public function getUrl(string $openFrom): ?string
	{
		if (!$this->shouldRenderButton() || $this->getLockInfoHelperCode() !== null)
		{
			return null;
		}

		return $this->getDashboard()?->getEmbeddedUrl($openFrom);
	}

	public function getOnClickJsCode(string $openFrom): ?JsCode
	{
		if (!$this->shouldRenderButton())
		{
			return null;
		}

		$lockCode = $this->getLockInfoHelperCode();
		if ($lockCode !== null)
		{
			return new JsCode("top.BX.UI.InfoHelper.show('" . \CUtil::JSEscape($lockCode) . "');");
		}

		return new JsCode("window.open('" . \CUtil::JSEscape((string)$this->getUrl($openFrom)) . "', '_blank');");
	}

	private function getDashboard(): ?Dashboard
	{
		if ($this->dashboard)
		{
			return $this->dashboard;
		}

		$this->dashboard = Dashboard::create(self::DASHBOARD_APP_ID);

		return $this->dashboard;
	}
}
