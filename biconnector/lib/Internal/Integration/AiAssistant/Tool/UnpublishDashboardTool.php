<?php

namespace Bitrix\BIConnector\Internal\Integration\AiAssistant\Tool;

use Bitrix\AiAssistant\Exceptions\McpException;
use Bitrix\BIConnector\Integration\Superset\Integrator\IntegratorFactory;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Public\Command\Share\DeleteSharesCommand;
use Bitrix\BIConnector\Superset\Logger\AiToolsLogger;
use Bitrix\Main\Loader;

final class UnpublishDashboardTool extends BaseBiTool
{
	public function getName(): string
	{
		return 'unpublish_dashboard';
	}

	public function getDescription(): string
	{
		return 'Moves a custom BI dashboard back to DRAFT, hiding it from other '
			. 'portal users. '
			. 'IMPORTANT — side effect: this revokes ALL public share links for '
			. 'the dashboard (mirrors the UI toggleDraft action). Existing share '
			. 'URLs stop working immediately and CANNOT be restored — recipients '
			. 'will need a freshly issued share link if the dashboard is '
			. 'published again. '
			. 'Idempotent: unpublishing an already-draft dashboard is a safe '
			. 'no-op (share-link revocation is skipped because there are no '
			. 'live shares on a draft). Does NOT touch charts, filters, or the '
			. 'title. '
			. 'TWO-STEP CONFIRMATION (MANDATORY): the first call (omit `confirm`, or pass '
			. '`confirm=false`) returns a preview (dashboard title and the share-link '
			. 'revocation impact) and changes nothing. Relay it to the user, get EXPLICIT '
			. 'approval, then call again with `confirm=true`. Never set `confirm=true` on your '
			. 'own, never chain the two calls, never infer approval from earlier conversation.';
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
						. 'unpublishing this dashboard (which permanently revokes its public share '
						. 'links). Omit or set false to only preview. Do NOT self-confirm or assume '
						. 'approval from earlier context.',
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

		$loadResult = $this->loadDashboardForWrite($dashboardId, $userId);
		if (!$loadResult->isSuccess())
		{
			throw self::toMcpException($loadResult);
		}
		$dashboard = $loadResult->getData()['dashboard'];
		$externalId = (int)$dashboard->getExternalId();
		$wasPublished = $dashboard->getStatus() === SupersetDashboardTable::DASHBOARD_STATUS_READY;

		$confirm = ($args['confirm'] ?? false) === true;
		if (!$confirm)
		{
			return [
				'requiresConfirmation' => true,
				'action' => 'unpublish_dashboard',
				'id' => $dashboardId,
				'name' => (string)$dashboard->getTitle(),
				'impact' => $wasPublished
					? 'The dashboard will be moved to DRAFT and hidden from other portal users. '
						. 'ALL its public share links will be permanently revoked and cannot be restored.'
					: 'The dashboard is already a draft; this is a safe no-op (no live share links to revoke).',
				'confirmHint' => 'Show this preview to the user; only AFTER they explicitly approve, call unpublish_dashboard again with the same dashboardId and confirm=true.',
			];
		}

		$response = IntegratorFactory::getInstance()->updateDashboard($externalId, [
			'published' => false,
		]);
		if ($response->hasErrors())
		{
			throw self::unavailableBiException(
				$response->getErrors(),
				[
					'stage' => 'unpublish_dashboard',
					'user_id' => $userId,
					'dashboard_id' => $dashboardId,
					'external_id' => $externalId,
				],
			);
		}

		$dashboard->setStatus(SupersetDashboardTable::DASHBOARD_STATUS_DRAFT);
		$saveResult = $dashboard->save();
		if (!$saveResult->isSuccess())
		{
			AiToolsLogger::logErrors(
				$saveResult->getErrors(),
				[
					'stage' => 'unpublish_dashboard.local_save',
					'user_id' => $userId,
					'dashboard_id' => $dashboardId,
					'external_id' => $externalId,
				],
			);
		}

		// Mirror dashboard.toggleDraftAction: a READY → DRAFT transition must
		// revoke every public share link, otherwise guests keep reading a
		// dashboard the owner no longer considers published. Skipped when the
		// dashboard was already DRAFT (idempotent re-call — DeleteSharesCommand
		// would be a no-op anyway, but skipping saves a repo round-trip).
		if ($wasPublished)
		{
			(new DeleteSharesCommand(dashboardId: $dashboardId))->run();
		}

		return [
			'id' => $dashboardId,
			'name' => (string)$dashboard->getTitle(),
			'published' => false,
		];
	}
}
