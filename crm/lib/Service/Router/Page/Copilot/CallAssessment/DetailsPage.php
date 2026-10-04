<?php

namespace Bitrix\Crm\Service\Router\Page\Copilot\CallAssessment;

use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Service\Router\AbstractPage;
use Bitrix\Crm\Service\Router\Component\Component;
use Bitrix\Crm\Service\Router\Component\SidePanelWrapper;
use Bitrix\Crm\Service\Router\Contract;
use Bitrix\Crm\Service\Router\Dto\SidePanelAnchorOptions;
use Bitrix\Crm\Service\Router\Dto\SidePanelAnchorRule;
use Bitrix\Crm\Service\Router\Enum\Scope;
use Bitrix\Crm\Service\Router\Route;
use Bitrix\Main\HttpRequest;

final class DetailsPage extends AbstractPage
{
	protected bool $isPlainView = true;
	private const COMPONENT_NAME = 'bitrix:crm.copilot.call.assessment.details.wrapper';

	public function __construct(
		private readonly int $callAssessmentId,
		HttpRequest $request,
		?Scope $currentScope,
	)
	{
		parent::__construct($request, $currentScope);
	}

	public function component(): Contract\Component
	{
		return new Component(
			name: self::COMPONENT_NAME,
			parameters: [
				'callAssessmentId' => $this->callAssessmentId,
			],
		);
	}

	protected function configureSidePanel(SidePanelWrapper $sidePanel): void
	{
		$sidePanel->isUsePadding = false;
		$sidePanel->isUseBackgroundContent = false;
		$sidePanel->isPlainView = true;
	}

	public static function routes(): array
	{
		return [
			(new Route('copilot-call-assessment/details/{callAssessmentId}/'))
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
		$width = AIManager::isCallScoringV2Enabled() ? 1045 : 700;

		return [
			(new SidePanelAnchorRule("copilot-call-assessment/details/[0-9]+/?"))
				->scopes(self::scopes())
				->configureOptions(function (SidePanelAnchorOptions $options) use ($width){
					$options
						->setCacheable(false)
						->setAllowChangeHistory(false)
						->setWidth($width)
					;
				})
			,
		];
	}
}
