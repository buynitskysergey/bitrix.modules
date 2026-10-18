<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Access\Model\DashboardAccessItem;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Integration\Superset\Integrator\Request\IntegratorResponse;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Superset\Logger\AiToolsLogger;
use Bitrix\Main\Loader;

final class DeleteDashboardTool extends BaseBiTool
{
	public function getName(): string
	{
		return 'delete_dashboard';
	}

	public function getDescription(): string
	{
		return 'Permanently deletes a custom BI dashboard from BI Constructor (Apache '
			. 'Superset). DESTRUCTIVE and irreversible. Only custom dashboards can be '
			. 'deleted — system and marketplace dashboards are rejected. The charts '
			. 'that were on the dashboard are NOT deleted (use delete_chart for those). '
			. 'A dashboard that has been copied by others cannot be deleted. '
			. 'TWO-STEP CONFIRMATION (MANDATORY): the first call (omit `confirm`, or pass '
			. '`confirm=false`) returns a preview (dashboard title) and changes nothing. Relay '
			. 'it to the user, get EXPLICIT approval, then call again with `confirm=true`. Never '
			. 'set `confirm=true` on your own, never chain the two calls, never infer approval '
			. 'from earlier conversation. '
			. 'Returns the preview on the first call, and `{deleted:true, id, name}` on '
			. 'the confirmed call. Idempotent: deleting an already-deleted dashboard is a '
			. 'safe no-op and returns `{deleted:true, already_deleted:true}`.';
	}

	public function getInputSchema(): array
	{
		return [
			'type' => 'object',
			'properties' => [
				'dashboardId' => [
					'type' => 'integer',
					'description' => 'Local dashboard id (from create_dashboard or list_dashboards). '
						. 'Positive integer.',
					'minimum' => 1,
				],
				'confirm' => [
					'type' => 'boolean',
					'description' => 'Set to true ONLY after the user has explicitly approved '
						. 'this previewed deletion. Omit or set false to only preview the impact. '
						. 'Do NOT self-confirm or assume approval from earlier context.',
				],
			],
			'required' => ['dashboardId'],
			'additionalProperties' => false,
		];
	}

	protected function executeStructured(int $userId, ...$args): array
	{
		Loader::includeModule('biconnector');

		$dashboardId = (int)($args['dashboardId'] ?? 0);
		if ($dashboardId <= 0)
		{
			throw new McpException('dashboardId is required and must be a positive integer.');
		}

		// loadDashboardForWrite enforces: feature flag, existence, custom type,
		// READY/DRAFT status, EDIT ACL and a synced external id.
		$loadResult = $this->loadDashboardForWrite($dashboardId, $userId);
		if (!$loadResult->isSuccess())
		{
			// A dashboard that no longer exists locally is an idempotent no-op —
			// symmetric with delete_chart / delete_dataset. Any other failure
			// (feature gate, non-custom type, status, ACL, sync) stays a real error.
			foreach ($loadResult->getErrors() as $error)
			{
				if ($error->getCode() === 'dashboard_not_found')
				{
					return [
						'deleted' => true,
						'id' => $dashboardId,
						'name' => '',
						'already_deleted' => true,
					];
				}
			}

			throw self::toMcpException($loadResult);
		}
		$dashboard = $loadResult->getData()['dashboard'];

		// Delete needs its own permission on top of the EDIT check above.
		$accessItem = DashboardAccessItem::createFromId($dashboardId);
		if (!$this->getAccessController($userId)->check(ActionDictionary::ACTION_BIC_DASHBOARD_DELETE, $accessItem))
		{
			throw new McpException('Access denied: ACTION_BIC_DASHBOARD_DELETE is required to delete dashboards.');
		}

		// Mirror dashboard.php::deleteAction(): a dashboard that has been copied
		// (some other row points to it via SOURCE_ID) must not be deleted.
		$hasCopiedDashboards = SupersetDashboardTable::getList([
			'select' => ['SOURCE_ID'],
			'filter' => ['=SOURCE_ID' => $dashboardId],
			'limit' => 1,
		])->fetch();
		if ($hasCopiedDashboards)
		{
			throw new McpException(
				'This dashboard has been copied by other dashboards and cannot be deleted. '
				. 'Delete the copies first.'
			);
		}

		$name = (string)$dashboard->getTitle();
		$externalId = (int)$dashboard->getExternalId();

		$confirm = ($args['confirm'] ?? false) === true;
		if (!$confirm)
		{
			return [
				'requiresConfirmation' => true,
				'action' => 'delete_dashboard',
				'id' => $dashboardId,
				'name' => $name,
				'impact' => 'The dashboard will be permanently deleted. Its charts are kept '
					. 'and remain available for other dashboards. This cannot be undone.',
				'confirmHint' => 'Show this preview to the user; only AFTER they explicitly approve, call delete_dashboard again with the same dashboardId and confirm=true.',
			];
		}

		$response = IntegratorFactory::getInstance()->deleteDashboard([$externalId], false);
		if ($response->hasErrors())
		{
			// Already gone on the Superset side — drop the local row and report success.
			if ($response->getStatus() === IntegratorResponse::STATUS_NOT_FOUND)
			{
				$deleteResult = $dashboard->delete();
				if (!$deleteResult->isSuccess())
				{
					AiToolsLogger::logErrors(
						$deleteResult->getErrors(),
						[
							'stage' => 'delete_dashboard.local_delete_not_found',
							'user_id' => $userId,
							'dashboard_id' => $dashboardId,
							'external_id' => $externalId,
						],
					);
				}

				return [
					'deleted' => true,
					'id' => $dashboardId,
					'name' => $name,
					'already_deleted' => true,
				];
			}

			throw self::unavailableBiException(
				$response->getErrors(),
				[
					'stage' => 'delete_dashboard',
					'user_id' => $userId,
					'dashboard_id' => $dashboardId,
					'external_id' => $externalId,
				],
			);
		}

		$deleteResult = $dashboard->delete();
		if (!$deleteResult->isSuccess())
		{
			AiToolsLogger::logErrors(
				$deleteResult->getErrors(),
				[
					'stage' => 'delete_dashboard.local_delete',
					'user_id' => $userId,
					'dashboard_id' => $dashboardId,
					'external_id' => $externalId,
				],
			);
		}

		return [
			'deleted' => true,
			'id' => $dashboardId,
			'name' => $name,
		];
	}
}
