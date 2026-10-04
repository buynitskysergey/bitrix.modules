<?php

namespace Bitrix\Crm\Controller\Settings;

use Bitrix\Crm\Controller\Base;
use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Config;
use Bitrix\Crm\Integration\AI\Operation\Autostart\CallAssessmentDefault;
use Bitrix\Crm\Integration\AI\Operation\Autostart\FillFieldsSettings;
use Bitrix\Crm\Integration\AI\Operation\Autostart\Slider\AutomationScenarioCompiler;
use Bitrix\Crm\Integration\AI\Operation\Autostart\Slider\AutomationScenarioRegistry;
use Bitrix\Crm\Integration\AI\Operation\Autostart\Slider\AutomationSliderCommandService;
use Bitrix\Crm\Integration\AI\Operation\Autostart\Slider\AutomationSliderQueryService;
use Bitrix\Crm\Integration\AI\Operation\Autostart\Slider\AutostartSettingsRepository;
use Bitrix\Crm\Integration\AI\Operation\Autostart\Slider\GlobalFeatureReader;
use Bitrix\Crm\Integration\AI\Operation\Autostart\Slider\ScopeResolver;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\ActionFilter\ContentType;
use Bitrix\Main\Engine\ActionFilter\Scope;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;

class AI extends Base
{
	protected function getDefaultPreFilters(): array
	{
		$filters = parent::getDefaultPreFilters();

		$filters[] = new Scope(Scope::AJAX);
		$filters[] = new ContentType([ContentType::JSON]); // its pain to work with empty arrays, nulls and booleans otherwise

		$filters[] = new class extends \Bitrix\Main\Engine\ActionFilter\Base {
			public function onBeforeAction(Event $event): ?EventResult
			{
				if (!AIManager::isAiCallProcessingEnabled())
				{
					$this->addError(ErrorCode::getAccessDeniedError());

					return new EventResult(EventResult::ERROR, null, 'crm', $this);
				}

				return null;
			}
		};

		return $filters;
	}

	public function configureActions(): array
	{
		$configureActions = parent::configureActions();
		foreach (['getAutomationSlider', 'saveAutomationSlider'] as $actionName)
		{
			$configureActions[$actionName] = [
				'+prefilters' => [
					new ActionFilter\HttpMethod(
						[
							ActionFilter\HttpMethod::METHOD_POST,
						]
					),
				],
			];
		}

		return $configureActions;
	}

	public function getAutomationSliderAction(int $entityTypeId, ?int $categoryId = null): ?array
	{
		$scope = $this->resolveScopeForUser($entityTypeId, $categoryId);
		if ($scope === null)
		{
			return null;
		}

		if (!FillFieldsSettings::checkReadPermissions($scope['entityTypeId'], $scope['categoryId']))
		{
			$this->addError(ErrorCode::getAccessDeniedError());

			return null;
		}

		$slider = $this->getAutomationSliderQueryService()->build(
			$scope,
			Config::getLanguageId(
				Container::getInstance()->getContext()->getUserId(),
				$scope['entityTypeId'],
				$scope['categoryId'],
			),
		);

		return ['slider' => $slider];
	}

	public function saveAutomationSliderAction(
		array $scenarioUpdates,
		string $revision,
		int $entityTypeId,
		?int $categoryId = null,
	): ?array
	{
		$scope = $this->resolveScopeForUser($entityTypeId, $categoryId);
		if ($scope === null)
		{
			return null;
		}

		if (!FillFieldsSettings::checkSavePermissions($scope['entityTypeId'], $scope['categoryId']))
		{
			$this->addError(ErrorCode::getAccessDeniedError());

			return null;
		}

		$result = $this->getAutomationSliderCommandService()->save($scope, $revision, $scenarioUpdates);
		if (!$result->isSuccess())
		{
			$this->addErrors($result->getErrors());

			return null;
		}

		return $this->getAutomationSliderAction($scope['entityTypeId'], $scope['categoryId']);
	}

	private function getAutomationScopeResolver(): ScopeResolver
	{
		return new ScopeResolver();
	}

	/**
	 * Resolves the scope for user-initiated actions (opening/saving the slider).
	 * If the entity supports categories but has no default one, creates it on the fly
	 * (an explicit side effect under user intent, not a read).
	 */
	private function resolveScopeForUser(int $entityTypeId, ?int $categoryId): ?array
	{
		if (!in_array($entityTypeId, AIManager::SUPPORTED_ENTITY_TYPE_IDS, true))
		{
			$this->addError(new Error('invalid scope', 'invalid_scope'));

			return null;
		}

		$factory = Container::getInstance()->getFactory($entityTypeId);
		$resolver = $this->getAutomationScopeResolver();
		$scopeResult = $resolver->resolve($entityTypeId, $categoryId);
		if ($scopeResult->isSuccess())
		{
			return $scopeResult->getData();
		}

		if (($categoryId === null) && $factory?->isCategoriesSupported())
		{
			if (!FillFieldsSettings::checkSavePermissions($entityTypeId))
			{
				$this->addError(ErrorCode::getAccessDeniedError());

				return null;
			}

			$created = $factory->createDefaultCategoryIfNotExist();
			$scopeResult = $resolver->resolve($entityTypeId, $created->getId());
			if ($scopeResult->isSuccess())
			{
				return $scopeResult->getData();
			}
		}

		$this->addErrors($scopeResult->getErrors());

		return null;
	}

	private function getAutomationSliderQueryService(): AutomationSliderQueryService
	{
		return new AutomationSliderQueryService(
			new AutostartSettingsRepository(),
			new AutomationScenarioRegistry(),
			new GlobalFeatureReader(),
			new CallAssessmentDefault(),
		);
	}

	private function getAutomationSliderCommandService(): AutomationSliderCommandService
	{
		return new AutomationSliderCommandService(
			new AutostartSettingsRepository(),
			new AutomationScenarioCompiler(),
		);
	}
}
