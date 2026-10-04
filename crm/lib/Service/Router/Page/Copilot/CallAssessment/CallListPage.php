<?php

namespace Bitrix\Crm\Service\Router\Page\Copilot\CallAssessment;

use Bitrix\Crm\Service\Router\AbstractPage;
use Bitrix\Crm\Service\Router\Component\Component;
use Bitrix\Crm\Service\Router\Contract;
use Bitrix\Crm\Service\Router\Dto\SidePanelAnchorOptions;
use Bitrix\Crm\Service\Router\Dto\SidePanelAnchorRule;
use Bitrix\Crm\Service\Router\Enum\Scope;
use Bitrix\Crm\Service\Router\Route;
use Bitrix\Main\HttpRequest;

final class CallListPage extends AbstractPage
{
	private const COMPONENT_NAME = 'bitrix:crm.copilot.call.assessment.calllist';

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
				'ASSESSMENT_SETTING_ID' => $this->callAssessmentId,
			],
		);
	}

	public static function routes(): array
	{
		return [
			(new Route('copilot-call-assessment/{callAssessmentId}/calls/'))
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
			(new SidePanelAnchorRule("copilot-call-assessment/[0-9]+/calls/?"))
				->scopes(self::scopes())
				->configureOptions(function (SidePanelAnchorOptions $options) {
					$options
						->setCacheable(false)
						->setAllowChangeHistory(false)
						->setWidth(1215)
					;
				})
			,
		];
	}
}
