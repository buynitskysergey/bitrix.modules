<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorResponse;
use Bitrix\Main\Loader;

final class DeleteChartTool extends BaseBiTool
{
	public function getName(): string
	{
		return 'delete_chart';
	}

	public function getDescription(): string
	{
		return 'Permanently deletes a chart from BI Constructor (Apache Superset). '
			. 'DESTRUCTIVE and irreversible. The chart is also removed from every '
			. 'dashboard it is placed on; the dashboards themselves and their other '
			. 'charts are NOT deleted. '
			. 'TWO-STEP CONFIRMATION (MANDATORY): the first call (omit `confirm`, or pass '
			. '`confirm=false`) returns a preview of what will be deleted (chart name and the '
			. 'dashboards it sits on) and changes nothing. Relay it to the user, get EXPLICIT '
			. 'approval, then call again with `confirm=true`. Never set `confirm=true` on your '
			. 'own, never chain the two calls, never infer approval from earlier conversation. '
			. 'Returns the preview on the first call, and `{deleted:true, id, name}` on '
			. 'the confirmed call. Idempotent: deleting an already-deleted chart is a safe '
			. 'no-op and returns `{deleted:true, already_deleted:true}`.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'chartId' => [
					'type' => 'integer',
					'description' => 'Chart id to delete (from list_charts). Positive integer.',
					'minimum' => 1,
				],
				'confirm' => [
					'type' => 'boolean',
					'description' => 'Set to true ONLY after the user has explicitly approved '
						. 'this previewed deletion. Omit or set false to only preview the impact. '
						. 'Do NOT self-confirm or assume approval from earlier context.',
				],
			],
			'required' => ['chartId'],
			'additionalProperties' => false,
		];
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		Loader::includeModule('biconnector');

		if (!Feature::isBuilderEnabled())
		{
			throw new McpException('BI Builder feature is not enabled on this portal.');
		}

		$chartId = (int)($args['chartId'] ?? 0);
		if ($chartId <= 0)
		{
			throw new McpException('chartId is required and must be a positive integer.');
		}

		$accessController = $this->getAccessController($userId);
		if (!$accessController->check(ActionDictionary::ACTION_BIC_ACCESS))
		{
			throw new McpException('Access denied: ACTION_BIC_ACCESS is required.');
		}

		$integrator = IntegratorFactory::getInstance();

		$chartResponse = $integrator->getChartById($chartId);
		if ($chartResponse->hasErrors())
		{
			// Idempotent re-delete: the chart is already gone (retried call or a
			// second delete). Report success instead of a confusing "BI
			// unavailable" error — mirrors delete_dashboard.
			if ($chartResponse->getStatus() === IntegratorResponse::STATUS_NOT_FOUND)
			{
				return [
					'deleted' => true,
					'id' => $chartId,
					'name' => '',
					'already_deleted' => true,
				];
			}

			throw self::unavailableBiException(
				$chartResponse->getErrors(),
				['stage' => 'delete_chart.load', 'user_id' => $userId, 'chart_id' => $chartId],
			);
		}

		$chartData = $chartResponse->getData();
		if (!is_array($chartData))
		{
			$chartData = [];
		}

		$name = (string)($chartData['slice_name'] ?? $chartData['chart_name'] ?? '');
		$dashboards = is_array($chartData['dashboards'] ?? null) ? $chartData['dashboards'] : [];

		// Object-level scope: only a user who can edit one of the chart's consumer
		// dashboards may delete it (matches the web UI).
		$this->assertChartEditableByDashboards($userId, $dashboards);

		$confirm = ($args['confirm'] ?? false) === true;
		if (!$confirm)
		{
			return [
				'requiresConfirmation' => true,
				'action' => 'delete_chart',
				'id' => $chartId,
				'name' => $name,
				'dashboards' => $dashboards,
				'impact' => 'The chart will be permanently deleted and removed from any '
					. 'dashboards it is placed on. This cannot be undone.',
				'confirmHint' => 'Show this preview to the user; only AFTER they explicitly approve, call delete_chart again with the same chartId and confirm=true.',
			];
		}

		$response = $integrator->deleteChart($chartId);
		if ($response->hasErrors())
		{
			// Race: the chart was deleted between preview and confirm. Treat the
			// 404 as success so a retried confirm does not surface a false error.
			if ($response->getStatus() === IntegratorResponse::STATUS_NOT_FOUND)
			{
				return [
					'deleted' => true,
					'id' => $chartId,
					'name' => $name,
					'already_deleted' => true,
				];
			}

			throw self::unavailableBiException(
				$response->getErrors(),
				['stage' => 'delete_chart', 'user_id' => $userId, 'chart_id' => $chartId],
			);
		}

		return [
			'deleted' => true,
			'id' => $chartId,
			'name' => $name,
		];
	}
}
