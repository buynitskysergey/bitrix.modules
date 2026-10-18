<?php

namespace Bitrix\TimemanMobile\Infrastructure\Controllers;

use Bitrix\Timeman\V2\Infrastructure\Controller;
use Bitrix\Timeman\V2\Public\Dto\FullReport\FullReport as FullReportDto;
use Bitrix\Timeman\V2\Public\Dto\FullReport\UserReports;
use Bitrix\Timeman\V2\Public\Dto\FullReport\UserReportsPage;
use Bitrix\TimemanMobile\Infrastructure\Controllers\Trait\ReportSettingsPayloadTrait;

class FullReport extends Base
{
	use ReportSettingsPayloadTrait;

	private const ITEM_TYPE_USER = 'WorkReportUser';
	private const ITEM_TYPE_ROW = 'WorkReportRow';

	/**
	 * @ajaxAction timemanmobile.FullReport.getUserReports
	 */
	public function getUserReportsAction(): ?array
	{
		$result = $this->forward(
			Controller\FullReport::class,
			'getUserReports',
		);

		if (!$this->errorCollection->isEmpty())
		{
			return null;
		}

		return [
			'items' => $result ? $result->toArray() : [],
		];
	}

	/**
	 * @ajaxAction timemanmobile.FullReport.getReportToSend
	 */
	public function getReportToSendAction(): ?array
	{
		$result = $this->forward(
			Controller\FullReport::class,
			'getReportToSend',
			[
				'force' => true,
				'withDraftFallback' => true,
			],
		);

		if (!$this->errorCollection->isEmpty())
		{
			return null;
		}

		$payload = $result?->toArray();
		if ($payload === null)
		{
			return null;
		}

		return [
			'report' => $payload,
			'settings' => $this->getReportSettingsPayload(),
		];
	}

	/**
	 * @ajaxAction timemanmobile.FullReport.getReportSettings
	 */
	public function getReportSettingsAction(): array
	{
		return [
			'settings' => $this->getReportSettingsPayload(),
		];
	}

	/**
	 * @ajaxAction timemanmobile.FullReport.getSubordinateUserReports
	 */
	public function getSubordinateUserReportsAction(): ?array
	{
		$result = $this->forward(
			Controller\FullReport::class,
			'getSubordinateUserReports',
		);

		if (!$this->errorCollection->isEmpty())
		{
			return null;
		}

		$settings = $this->getReportSettingsPayload();

		if (!$result instanceof UserReportsPage)
		{
			return [
				'items' => [],
				'hasMore' => false,
				'nextOffset' => null,
				'settings' => $settings,
			];
		}

		return [
			'items' => $this->flattenUserReportsPage($result),
			'hasMore' => $result->hasMore,
			'nextOffset' => $result->nextOffset,
			'settings' => $settings,
		];
	}

	/**
	 * @return list<array<string, mixed>>
	 */
	private function flattenUserReportsPage(UserReportsPage $page): array
	{
		$items = [];
		/** @var UserReports $userReports */
		foreach ($page->items as $userReports)
		{
			$userId = $userReports->userId;

			$items[] = [
				'id' => 'user-' . $userId,
				'itemType' => self::ITEM_TYPE_USER,
				'userId' => $userId,
				'user' => $userReports->user?->toArray() ?? ['id' => $userId],
			];

			/** @var FullReportDto $report */
			foreach ($userReports->reports as $report)
			{
				$items[] = [
					'id' => 'report-' . $report->id,
					'itemType' => self::ITEM_TYPE_ROW,
					'userId' => $report->userId,
					'report' => $report->toArray(),
				];
			}
		}

		return $items;
	}
}
