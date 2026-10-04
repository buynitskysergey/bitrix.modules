<?php

declare(strict_types=1);

namespace Bitrix\Crm\Service\Router\Page\Copilot\CallAssessment;

use Bitrix\Crm\Service\Router\AbstractPage;
use Bitrix\Crm\Service\Router\Component\Component;
use Bitrix\Crm\Service\Router\Component\SidePanelWrapper;
use Bitrix\Crm\Service\Router\Contract;
use Bitrix\Crm\Service\Router\Dto\SidePanelAnchorOptions;
use Bitrix\Crm\Service\Router\Dto\SidePanelAnchorRule;
use Bitrix\Crm\Service\Router\Enum\Scope;
use Bitrix\Crm\Service\Router\Route;

final class SummaryPage extends AbstractPage
{
	protected bool $isPlainView = true;

	private const COMPONENT_NAME = 'bitrix:crm.copilot.call.assessment.summary';

	public function component(): Contract\Component
	{
		return new Component(name: self::COMPONENT_NAME);
	}

	protected function configureSidePanel(SidePanelWrapper $sidePanel): void
	{
		$sidePanel->isUsePadding = false;
		$sidePanel->isUseBackgroundContent = false;
		$sidePanel->isPlainView = true;
		$sidePanel->isUseToolbar = false;
		$sidePanel->isHideToolbar = true;
	}

	public static function routes(): array
	{
		return [
			(new Route('copilot-call-assessment/summary/'))
				->setRelatedComponent(self::COMPONENT_NAME)
			,
		];
	}

	public static function scopes(): array
	{
		return [
			Scope::Crm,
		];
	}

	public static function getSidePanelAnchorRules(): array
	{
		return [
			(new SidePanelAnchorRule('copilot-call-assessment/summary/?'))
				->scopes(self::scopes())
				->configureOptions(function (SidePanelAnchorOptions $options) {
					$options
						->setCacheable(false)
						->setAllowChangeHistory(false)
						->setWidth(700)
					;
				})
			,
		];
	}
}
