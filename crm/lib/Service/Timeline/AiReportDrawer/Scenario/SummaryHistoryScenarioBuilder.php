<?php

namespace Bitrix\Crm\Service\Timeline\AiReportDrawer\Scenario;

use Bitrix\Crm\Integration\AI\AiMessageProvider;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Dto\ActivityContext;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Presentation\PresentationResolver;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Support\ActivityBadgeCleaner;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Support\MessageProvider;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Support\SettingsProvider;
use Bitrix\Crm\Service\Timeline\AiReportDrawer\Support\SummaryDataProvider;
use Bitrix\Main\Result;

final class SummaryHistoryScenarioBuilder implements ScenarioBuilderInterface
{
	private const SUMMARY_BLOCK_ID = 'summary';

	public function __construct(
		private readonly PresentationResolver $presentationResolver,
		private readonly SummaryDataProvider $summaryDataProvider,
		private readonly ActivityBadgeCleaner $activityBadgeCleaner,
		private readonly SettingsProvider $settingsProvider,
	)
	{
	}

	public function getCode(): ScenarioCode
	{
		return ScenarioCode::SUMMARY_HISTORY;
	}

	public function build(ActivityContext $context): Result
	{
		$result = new Result();
		$summaryHistory = $this->summaryDataProvider->loadSummaryHistory($context->request->activityId);
		$blocks = $this->prepareSummaryHistoryBlocks($summaryHistory);
		if (empty($blocks))
		{
			return $result->setData(['drawerData' => null]);
		}

		$this->activityBadgeCleaner->removeByOwner($context);

		return $result->setData([
			'drawerData' => [
				'title' => $this->prepareSummaryHistoryTitle($summaryHistory),
				'subtitle' => $this->presentationResolver->buildSubtitle($context),
				'settings' => $this->prepareSettings(),
				'record' => null,
				'infoPopup' => $this->presentationResolver->buildInfoPopup($context),
				'anchors' => $this->prepareSummaryHistoryAnchors($blocks),
				'assessmentBlocks' => [],
				'assessmentSetting' => null,
				'blocks' => $blocks,
				'aiDisclaimer' => AiMessageProvider::getAiDisclaimerData()['html'] ?? null,
			],
		]);
	}

	private function prepareSettings(): array
	{
		return array_values(array_filter([
			$this->settingsProvider->getButton(SettingsProvider::SHARE_SLIDER),
		]));
	}

	private function prepareSummaryHistoryBlocks(array $summaryHistory): array
	{
		$blocks = [];

		foreach ($summaryHistory as $index => $summary)
		{
			$text = (string)($summary['summary'] ?? '');
			if ($text === '')
			{
				continue;
			}

			$jobId = (int)($summary['jobId'] ?? 0);
			$blockId = $jobId > 0
				? self::SUMMARY_BLOCK_ID . '-' . $jobId
				: self::SUMMARY_BLOCK_ID . '-' . $index
			;

			$blocks[] = [
				'blockId' => $blockId,
				'blockType' => self::SUMMARY_BLOCK_ID,
				'title' => MessageProvider::getSummaryBlockTitle(),
				'text' => $text,
				'createdAt' => is_numeric($summary['createdAt'] ?? null) ? (int)$summary['createdAt'] : null,
				'aiLanguage' => is_scalar($summary['aiLanguage'] ?? null) ? (string)$summary['aiLanguage'] : null,
				'minimized' => ($index !== 0),
			];
		}

		return $blocks;
	}

	private function prepareSummaryHistoryTitle(array $summaryHistory): string
	{
		$latestTheme = (string)($summaryHistory[0]['theme'] ?? '');
		if ($latestTheme !== '')
		{
			return $latestTheme;
		}

		return MessageProvider::getSummaryBlockTitle();
	}

	private function prepareSummaryHistoryAnchors(array $blocks): array
	{
		if (empty($blocks))
		{
			return [];
		}

		return [[
			'text' => MessageProvider::getSummaryBlockTitle(),
			'isActive' => true,
			'blockId' => (string)($blocks[0]['blockId'] ?? self::SUMMARY_BLOCK_ID),
		]];
	}
}
