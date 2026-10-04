<?php

namespace Bitrix\Crm\Service\Timeline\Item;

use Bitrix\Crm\Badge\Badge;
use Bitrix\Crm\Badge\SourceIdentifier;
use Bitrix\Crm\Badge\Type\AiCallFieldsFillingResult;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Operation\FillItemFieldsFromCallTranscription;
use Bitrix\Crm\Integration\AI\Operation\Scenario;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Timeline\Item\Activity\AI\Action\AIItemsBuilder;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock\ActionBar\ActionBar;
use Bitrix\Crm\Service\Timeline\Layout\Body\ContentBlock\ActionBar\ActionBarItem;
use Bitrix\Crm\Service\Timeline\Layout\Footer\Button;
use Bitrix\Crm\Service\Timeline\Layout\Header\Tag;
use Bitrix\Crm\Service\Timeline\Layout\Menu;
use Bitrix\Crm\Service\Timeline\Layout\Menu\MenuItemDesign;
use Bitrix\Crm\Service\Timeline\Layout\Menu\MenuItemSubmenu;
use Bitrix\Main\Localization\Loc;
use Bitrix\Ui\Public\Enum\IconSet\Outline;
use CCrmOwnerType;

abstract class AIActivity extends Activity
{
	private const MENU_DEFAULT_SORT = 8002;

	private ?AIActivityService $aiService = null;
	private ?AIItemsBuilder $aiItemsBuilder = null;

	/**
	 * @return string[]
	 */
	abstract protected function getScenarios(): array;

	/**
	 * @return bool
	 */
	abstract protected function canShowAIActions(): bool;

	/**
	 * Full AI scenario set (transcribe/summarize/scoring/analyze/full) stays limited to the classic
	 * Deal/Lead types. Extended fill-fields target types (any Factory-based entity: contact, company,
	 * smart process, quote, ...) expose only the fill-related actions. See fill-fields-any-entity.
	 *
	 * @param string[] $scenarios
	 *
	 * @return string[]
	 */
	final protected function limitScenariosForExtendedEntityTypes(array $scenarios): array
	{
		if (in_array($this->getContext()->getEntityTypeId(), AIManager::SUPPORTED_ENTITY_TYPE_IDS, true))
		{
			return $scenarios;
		}

		return array_values(array_intersect(
			$scenarios,
			[Scenario::FILL_FIELDS_SCENARIO, Scenario::CONFIRM_FIELDS_SCENARIO],
		));
	}

	final public function needShowNotes(): bool
	{
		return true;
	}

	/**
	 * @return Button[]
	 */
	final public function getAIButtons(): array
	{
		return $this->getAIItemsBuilder()?->getButtons() ?? [];
	}

	/**
	 * @return array|Menu\MenuItem[]
	 */
	final public function getAIMenuItems(): array
	{
		$items = $this->getAIItemsBuilder()?->getMenuItems() ?? [];
		if (empty($items))
		{
			return [];
		}

		return [
			(new MenuItemSubmenu(AIManager::getCopilotName(), (new Menu())->setItems($items)))
				->setSort(self::MENU_DEFAULT_SORT)
				->setDesign(MenuItemDesign::COPILOT)
				->setBadgeText(Menu\BadgeText::createNew())
				->setIcon(Outline::COPILOT)
				->setScopeWeb()
		];
	}

	/**
	 * @return Tag[]
	 */
	final public function getAITags(): array
	{
		if (!$this->isAIScope())
		{
			return [];
		}

		if ($this->getAIService()->isFieldsFillingWrong())
		{
			return [
				'copilotWarning' => (new Tag(
					Loc::getMessage('CRM_TIMELINE_TAG_COPILOT_WARNING', ['#COPILOT_NAME#' => AIManager::getCopilotName()]),
					Tag::TYPE_WARNING
				))->setScopeWeb()
			];
		}

		$jobResult = $this->getAIService()->getAIJobResult(FillItemFieldsFromCallTranscription::TYPE_ID);
		if ($jobResult?->isSuccess())
		{
			return [
				'copilotDone' => (new Tag(
					Loc::getMessage('CRM_TIMELINE_TAG_COPILOT_DONE', ['#COPILOT_NAME#' => AIManager::getCopilotName()]),
					Tag::TYPE_AI
				))->setScopeWeb()
			];
		}

		$limitExceededBadge = Container::getInstance()->getBadge(
			Badge::AI_FIELDS_FILLING_RESULT,
			AiCallFieldsFillingResult::ERROR_LIMIT_EXCEEDED,
		);
		$itemIdentifier = $this->getContext()->getIdentifier();
		$sourceIdentifier = new SourceIdentifier(
			SourceIdentifier::CRM_OWNER_TYPE_PROVIDER,
			CCrmOwnerType::Activity,
			$this->getActivityId(),
		);

		if ($limitExceededBadge->isBound($itemIdentifier, $sourceIdentifier))
		{
			return [
				'copilotLimitExceeded' => (new Tag(
					AiCallFieldsFillingResult::getLimitExceededTextValue(),
					Tag::TYPE_FAILURE,
				))->setScopeWeb()
			];
		}

		return [];
	}

	protected function getAIService(): AIActivityService
	{
		if ($this->aiService === null)
		{
			$this->aiService = new AIActivityService($this->getActivityId(), $this->getContext());
		}

		return $this->aiService;
	}

	final protected function areAIActionsAvailable(): bool
	{
		if (!$this->getAssociatedEntityModel())
		{
			return false;
		}

		return $this->isAIScope()
			&& $this->hasUpdatePermission()
			&& $this->getAIService()->isItemHashValid()
		;
	}

	final protected function isAIScope(): bool
	{
		return $this->getAIService()->isAIScope();
	}

	final protected function buildAiActionBar(): ActionBar
	{
		$bar = (new ActionBar());
		if (!$this->isAIScope())
		{
			return $bar;
		}

		$summarizeActivityId = $this->getSummarizeActivityId();
		$aiService = $summarizeActivityId !== $this->getActivityId()
			? $this->getAIService()->withSummarizeActivityId($summarizeActivityId)
			: $this->getAIService()
		;

		$list = $aiService->getSummarizeTranscriptionList();
		if (empty($list))
		{
			return $bar;
		}

		$viewSummaryItem = $this->createViewCopilotSummaryItem($list);
		if ($viewSummaryItem)
		{
			$bar->addItem('viewCopilotSummary', $viewSummaryItem);
		}

		$viewSummaryDrawerItem = $this->createViewCopilotSummaryDrawerItem($list);
		if ($viewSummaryDrawerItem)
		{
			$bar->addItem('viewCopilotSummaryDrawer', $viewSummaryDrawerItem);
		}

		return $bar;
	}

	protected function getSummarizeActivityId(): int
	{
		return $this->getActivityId();
	}

	protected function getRootActivityIdForActions(): int
	{
		return $this->getActivityId();
	}

	protected function createViewCopilotSummaryItem(array $list): ?ActionBarItem
	{
		return null;
	}

	protected function createViewCopilotSummaryDrawerItem(array $list): ?ActionBarItem
	{
		return null;
	}

	// region Internal utils
	private function getAIItemsBuilder(): ?AIItemsBuilder
	{
		if ($this->aiItemsBuilder !== null)
		{
			return $this->aiItemsBuilder;
		}

		if (!$this->areAIActionsAvailable() || !$this->canShowAIActions())
		{
			return null;
		}

		$this->aiItemsBuilder = AIItemsBuilder::create(
			$this->getActivityId(),
			$this->getContext(),
			$this->getAssociatedEntityModel()
		)
			->withRootActivityId($this->getRootActivityIdForActions())
		;

		foreach ($this->getScenarios() as $scenario)
		{
			$this->aiItemsBuilder->withScenario($scenario);
		}

		return $this->aiItemsBuilder;
	}
	// endregion
}
