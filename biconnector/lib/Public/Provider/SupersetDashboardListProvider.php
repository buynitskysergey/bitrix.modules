<?php

declare(strict_types=1);

namespace Bitrix\BIConnector\Public\Provider;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboard;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardGroupTable;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Integration\Superset\Repository\DashboardGroupRepository;
use Bitrix\BIConnector\Integration\Superset\SupersetController;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\BIConnector\Superset\MarketAccessManager;
use Bitrix\Main\Type\DateTime;

/**
 * Public provider that returns a union list of dashboard-group rows and dashboard rows
 * (folders + reports) filtered by the current user's view rights, with search and pagination.
 *
 * Owner of the domain visibility filtering (rights + draft) for the mobile list.
 * Consumed PHP-to-PHP by the biconnectormobile thin controller.
 */
final class SupersetDashboardListProvider
{
	public const ENTITY_TYPE_DASHBOARD = 'dashboard';
	public const ENTITY_TYPE_GROUP = 'group';

	public const DISPLAY_STATUS_READY = 'ready';
	public const DISPLAY_STATUS_DRAFT = 'draft';
	public const DISPLAY_STATUS_FAILED = 'failed';
	public const DISPLAY_STATUS_LOAD = 'load';
	public const DISPLAY_STATUS_NOT_AVAILABLE = 'not_available';
	public const DISPLAY_STATUS_LIMIT_EXCEEDED = 'limit_exceeded';
	public const DISPLAY_STATUS_NOT_FOUND = 'not_found';
	public const DISPLAY_STATUS_PROHIBITED = 'prohibited';

	private const RAW_STATUS_MAP = [
		SupersetDashboardTable::DASHBOARD_STATUS_LOAD => self::DISPLAY_STATUS_LOAD,
		SupersetDashboardTable::DASHBOARD_STATUS_READY => self::DISPLAY_STATUS_READY,
		SupersetDashboardTable::DASHBOARD_STATUS_DRAFT => self::DISPLAY_STATUS_DRAFT,
		SupersetDashboardTable::DASHBOARD_STATUS_FAILED => self::DISPLAY_STATUS_FAILED,
	];

	/**
	 * @return array{items: array<int, array>, total: int}
	 */
	public function getList(
		string $searchString = '',
		?int $groupId = null,
		int $limit = 50,
		int $offset = 0,
		bool $withTotal = true,
		?string $scopeCode = null,
	): array
	{
		$filter = $this->buildFilter($searchString, $groupId, $scopeCode);

		$repository = (new SupersetController())->getUnionDashboardGroupRepository();

		$listParams = [
			'filter' => $filter,
			'order' => ['ID' => 'desc'],
		];
		if ($limit > 0)
		{
			$listParams['limit'] = $limit;
		}
		if ($offset > 0)
		{
			$listParams['offset'] = $offset;
		}

		$rows = $repository->getList($listParams, SupersetInitializer::isSupersetExist());
		$total = $withTotal ? $repository->getCount(['filter' => $filter]) : 0;

		$items = [];
		foreach ($rows as $row)
		{
			/** @var array<string, mixed> $row The union repository returns raw associative rows, not Dashboard models. */
			$items[] = $row['ENTITY_TYPE'] === DashboardGroupRepository::TYPE_GROUP
				? $this->mapGroup($row)
				: $this->mapDashboard($row)
			;
		}

		return [
			'items' => $items,
			'total' => $total,
		];
	}

	private function buildFilter(string $searchString, ?int $groupId, ?string $scopeCode = null): array
	{
		$filter = $this->getAccessFilter();

		if ($searchString !== '')
		{
			$filter['%TITLE'] = $searchString;
		}

		if ($scopeCode !== null && $scopeCode !== '')
		{
			// Scope mode: flat list of the zone's dashboards (parity with ScopeService::getDashboardListByScope).
			// Overrides groupId and the root-browse OR branch - groups do not take part.
			$filter['=SCOPE.SCOPE_CODE'] = $scopeCode;

			return $filter;
		}

		if ($groupId !== null && $groupId > 0)
		{
			$filter['GROUPS.ID'] = $groupId;
		}
		elseif ($searchString === '')
		{
			// Root browse without search: show only top-level entities - groupless dashboards and groups.
			// During search the restriction is dropped so it spans all dashboards regardless of group.
			$filter[] = [
				'LOGIC' => 'OR',
				'GROUPS.ID' => null,
				'=ENTITY_TYPE' => DashboardGroupRepository::TYPE_GROUP,
			];
		}

		return $filter;
	}

	/**
	 * Reproduces the grid's key extraction: the union repository expects DASHBOARD_ID / GROUP_ID
	 * keys, not a raw ['=ID' => [...]] filter (which would leak across both union sub-tables).
	 * Null from getEntityFilter means full access - no restriction is added.
	 */
	private function getAccessFilter(): array
	{
		$result = [];
		$accessController = AccessController::getCurrent();

		$dashboardFilter = $accessController->getEntityFilter(
			ActionDictionary::ACTION_BIC_DASHBOARD_VIEW,
			SupersetDashboardTable::class,
		);
		if (!empty($dashboardFilter))
		{
			$result['DASHBOARD_ID'] = $dashboardFilter['=ID'] ?? [];
		}

		$groupFilter = $accessController->getEntityFilter(
			ActionDictionary::ACTION_BIC_DASHBOARD_VIEW,
			SupersetDashboardGroupTable::class,
		);
		if (!empty($groupFilter))
		{
			$result['GROUP_ID'] = $groupFilter['=ID'] ?? [];
		}

		return $result;
	}

	private function mapGroup(array $row): array
	{
		return [
			'entityType' => self::ENTITY_TYPE_GROUP,
			'id' => (int)($row['ID'] ?? 0),
			'title' => (string)($row['TITLE'] ?? ''),
			'groupType' => (string)($row['TYPE'] ?? ''),
			'countDashboards' => (int)($row['COUNT_DASHBOARDS'] ?? 0),
		];
	}

	private function mapDashboard(array $row): array
	{
		$dateModify = $row['DATE_MODIFY'] ?? null;

		return [
			'entityType' => self::ENTITY_TYPE_DASHBOARD,
			'id' => (int)$row['ID'],
			'title' => (string)($row['TITLE'] ?? ''),
			'status' => (string)($row['STATUS'] ?? ''),
			'displayStatus' => $this->computeDisplayStatus($row),
			'type' => (string)($row['TYPE'] ?? ''),
			'dateModify' => $dateModify instanceof DateTime ? $dateModify->getTimestamp() : 0,
			'authorId' => (int)($row['CREATED_BY_ID'] ?? 0),
		];
	}

	/**
	 * Pure reproduction of StatusFieldAssembler::prepareColumn branching, without grid context
	 * and without HTML. Result is mapped to displayStatus values.
	 */
	private function computeDisplayStatus(array $row): string
	{
		$status = (string)($row['STATUS'] ?? '');
		$type = (string)($row['TYPE'] ?? '');

		if (SupersetInitializer::getSupersetStatus() === SupersetInitializer::SUPERSET_STATUS_LIMIT_EXCEEDED)
		{
			return self::DISPLAY_STATUS_LIMIT_EXCEEDED;
		}

		if ($status === SupersetDashboardTable::DASHBOARD_STATUS_NOT_INSTALLED)
		{
			// Web grid renders a non-custom NOT_INSTALLED dashboard under the READY label
			// (StatusFieldAssembler::getStatusLabelByStatusType shares the NOT_INSTALLED/READY case).
			return $type === SupersetDashboardTable::DASHBOARD_TYPE_CUSTOM
				? self::DISPLAY_STATUS_DRAFT
				: self::DISPLAY_STATUS_READY
			;
		}

		if (SupersetInitializer::isSupersetLoading())
		{
			return $this->mapRawStatus($status);
		}

		if (!$this->isSupersetAvailable())
		{
			return self::DISPLAY_STATUS_NOT_AVAILABLE;
		}

		if (
			($row['EDIT_URL'] ?? '') === ''
			&& in_array($status, SupersetDashboard::getActiveDashboardStatuses(), true)
		)
		{
			return self::DISPLAY_STATUS_NOT_FOUND;
		}

		if (empty($row['IS_ACCESS_ALLOWED']))
		{
			return self::DISPLAY_STATUS_PROHIBITED;
		}

		if (!MarketAccessManager::getInstance()->isDashboardAvailableByType($type))
		{
			return self::DISPLAY_STATUS_PROHIBITED;
		}

		return $this->mapRawStatus($status);
	}

	private function isSupersetAvailable(): bool
	{
		if (SupersetInitializer::isSupersetExist())
		{
			return !SupersetInitializer::isSupersetUnavailable();
		}

		return true;
	}

	private function mapRawStatus(string $status): string
	{
		return self::RAW_STATUS_MAP[$status] ?? self::DISPLAY_STATUS_READY;
	}
}
