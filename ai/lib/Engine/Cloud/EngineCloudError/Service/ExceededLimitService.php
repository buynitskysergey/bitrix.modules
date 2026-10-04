<?php declare(strict_types=1);

namespace Bitrix\AI\Engine\Cloud\EngineCloudError\Service;

use Bitrix\AI\Container;
use Bitrix\AI\Engine\Cloud\EngineCloudError\Dto\ExceededLimitDto;
use Bitrix\AI\Facade\Portal;
use Bitrix\AI\Integration\Baas\BaasTokenService;
use Bitrix\AI\Services\VibePlusUpsellService;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Ui\Public\Services\Copilot\CopilotNameService;
use Bitrix\UI\Util;

// Own phrases of the mapper (AI_ENGINE_ERROR_LIMIT_BAAS_MARKET and the rest). The Vibe+ phrases are
// not here: VibePlusUpsellService has its own lang file and loads it itself.
Loc::loadMessages(__FILE__);

class ExceededLimitService
{
	protected const SLIDER_CODE_REQUESTS = 'limit_copilot_requests_box';
	protected const SLIDER_CODE_BOOST = 'limit_boost_copilot_box';
	protected const SLIDER_CODE_BOX = 'limit_copilot_box';
	protected const SLIDER_CODE_WEST_TARIFF = 'limit_copilot';

	protected const ERROR_CODE_LIMIT_STANDARD = 'LIMIT_IS_EXCEEDED_MONTHLY';
	protected const ERROR_CODE_LIMIT_BAAS = 'LIMIT_IS_EXCEEDED_BAAS';
	protected const ERROR_CODE_LIMIT_BAAS_RATE_LIMIT = 'LIMIT_IS_EXCEEDED_BAAS_RATE_LIMIT';
	protected const ERROR_CODE_RATE_LIMIT = 'RATE_LIMIT';

	protected const RATE_LIMIT_HELP_CODE = '24736310';

	public function mapExceededLimitError(Error $errorLimit): Error
	{
		$exceededLimitDto = $this->getErrorsLimitRules($errorLimit);
		$mainData = $exceededLimitDto->toArray();
		$customData = [
			'sliderCode' => $exceededLimitDto->sliderCode,
			'showSliderWithMsg' => $exceededLimitDto->showSliderWithMsg,
			'msgForIm' => $exceededLimitDto->msgForIm,
			'mainData' => $mainData,
		];

		if (isset($mainData['vibePlusLimitState']))
		{
			$customData['vibePlusLimitState'] = $mainData['vibePlusLimitState'];
		}

		return new Error(
			$this->getMessageByErrorCode($exceededLimitDto->errorCode),
			$exceededLimitDto->errorCode,
			$customData,
		);
	}

	protected function getMessageByErrorCode(string $errorCode): string
	{
		if ($errorCode === static::ERROR_CODE_LIMIT_BAAS_RATE_LIMIT)
		{
			return Loc::getMessage('AI_ENGINE_ERROR_RATE_LIMIT_BAAS_MARKET');
		}
		if ($errorCode === static::ERROR_CODE_RATE_LIMIT)
		{
			return Loc::getMessage(
				'AI_ENGINE_ERROR_RATE_LIMIT_IS_EXCEEDED_MSGVER_1',
				[
					'#COPILOT_NAME#' => $this->getCopilotName(),
					'[helpdesklink]' => '<a href="' . $this->getLinkOnHelp() . '" target="blank">',
					'[/helpdesklink]' => '</a>',
				]
			);
		}

		return Loc::getMessage('AI_ENGINE_ERROR_LIMIT_IS_EXCEEDED');
	}

	protected function getErrorsLimitRules(Error $errorLimit): ExceededLimitDto
	{
		$errorData = $errorLimit->getCustomData();
		$isAvailableBaas = $this->isBaasAvailable();
		$errorCode = $this->getErrorCode($errorData, $isAvailableBaas);
		$isWestZone = $this->isWestZone();
		$sliderCode = $isWestZone ? static::SLIDER_CODE_WEST_TARIFF : static::SLIDER_CODE_REQUESTS;

		$msgForIm = Loc::getMessage(
			'AI_ENGINE_ERROR_LIMIT_IS_EXCEEDED_WITH_MORE',
			[
				'#COPILOT_NAME#' => $this->getCopilotName(),
				'#LINK#' => '/online/?FEATURE_PROMOTER=' . $sliderCode,
			]
		);

		if (empty($errorData['baasAvailable']))
		{
			// This branch is chosen by the absence of BAAS, not by the error code, so throttling
			// reaches it too - hence the explicit quota code, or "requests come too fast, wait a bit"
			// would be replaced with an upsell. The zone is asked for the reason spelled out in
			// Engine::throwError(): the gate reads the model from the controller group name, this
			// zone from the license region, and on an empty group name the two disagree.
			// Not reached in shipped configurations at all - the mapper is registered only without the
			// bitrix24 module, which the Vibe+ gate requires; kept for ai.use_bitrix24 = 'N'.
			$vibePlusMessage = $isWestZone && $errorCode === static::ERROR_CODE_LIMIT_STANDARD
				? $this->getVibePlusUpsellService()->resolveLimitMessage()
				: null;

			return new ExceededLimitDto(
				showSliderWithMsg: false,
				sliderCode: $vibePlusMessage?->sliderCode ?? $sliderCode,
				errorCode: $errorCode,
				msgForIm: $vibePlusMessage?->msgForIm ?? $msgForIm,
				isAvailableBaas: false,
				vibePlusLimitState: $vibePlusMessage?->state,
			);
		}

		if ($isWestZone)
		{
			$sliderCode = $isAvailableBaas ? static::SLIDER_CODE_BOOST : static::SLIDER_CODE_WEST_TARIFF;
		}
		else
		{
			$sliderCode = $isAvailableBaas ? static::SLIDER_CODE_BOOST : static::SLIDER_CODE_BOX;
		}
		$showSliderWithMsg = !$isAvailableBaas;

		if ($errorCode === static::ERROR_CODE_LIMIT_BAAS_RATE_LIMIT && Portal::isMarketAvailable())
		{
			$msgForIm = Loc::getMessage('AI_ENGINE_ERROR_RATE_LIMIT_BAAS_MARKET');
		}
		elseif ($errorCode === static::ERROR_CODE_LIMIT_BAAS && Portal::isMarketAvailable())
		{
			$showSliderWithMsg = true;
			$sliderCode = 'limit_subscription_market_access_buy_marketplus';
			$msgForIm = Loc::getMessage(
				'AI_ENGINE_ERROR_LIMIT_BAAS_MARKET',
				['#LINK#' => '/online/?FEATURE_PROMOTER=limit_subscription_market_access_buy_marketplus']
			);
		}
		elseif ($errorCode === static::ERROR_CODE_RATE_LIMIT)
		{
			$msgForIm = Loc::getMessage(
				'AI_ENGINE_ERROR_IM_RATE_LIMIT_IS_EXCEEDED_MSGVER_1',
				[
					'#COPILOT_NAME#' => $this->getCopilotName(),
					'#LINK#' => $this->getLinkOnHelp(),
				]
			);

			$sliderCode = 'redirect=detail&code=' . static::RATE_LIMIT_HELP_CODE;
		}
		else
		{
			$msgForIm = Loc::getMessage(
				'AI_ENGINE_ERROR_LIMIT_BAAS_MSGVER_1',
				[
					'#COPILOT_NAME#' => $this->getCopilotName(),
					'#LINK#' => '/online/?FEATURE_PROMOTER=' . $sliderCode,
				]
			);
		}

		return new ExceededLimitDto(
			showSliderWithMsg: $showSliderWithMsg,
			sliderCode: $sliderCode,
			errorCode: $errorCode,
			msgForIm: $msgForIm,
			isAvailableBaas: $isAvailableBaas,
			vibePlusLimitState: null,
		);
	}

	protected function getVibePlusUpsellService(): VibePlusUpsellService
	{
		return new VibePlusUpsellService();
	}

	protected function isWestZone(): bool
	{
		return Portal::isWestZone();
	}

	protected function isBaasAvailable(): bool
	{
		/** @var BaasTokenService $baasTokenService */
		$baasTokenService = Container::init()->getItem(BaasTokenService::class);

		return $baasTokenService->isAvailable();
	}

	protected function getErrorCode(array $errorData, bool $isAvailableBaas)
	{
		if (empty($errorData['errorLimitType']))
		{
			return static::ERROR_CODE_LIMIT_STANDARD;
		}

		if ($errorData['errorLimitType'] === static::ERROR_CODE_LIMIT_BAAS_RATE_LIMIT)
		{
			return static::ERROR_CODE_LIMIT_BAAS_RATE_LIMIT;
		}

		if ($errorData['errorLimitType'] === static::ERROR_CODE_RATE_LIMIT)
		{
			return static::ERROR_CODE_RATE_LIMIT;
		}

		if ($isAvailableBaas)
		{
			return static::ERROR_CODE_LIMIT_BAAS;
		}

		return $errorData['errorLimitType'];
	}

	private function getLinkOnHelp(): string
	{
		return Loader::includeModule('ui')
			? Util::getArticleUrlByCode(static::RATE_LIMIT_HELP_CODE)
			: 'https://helpdesk.bitrix24.ru/open/' . static::RATE_LIMIT_HELP_CODE;
	}

	private function getCopilotName(): string
	{
		return (new CopilotNameService())->getCopilotName();
	}
}
