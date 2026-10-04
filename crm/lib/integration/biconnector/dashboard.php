<?php

namespace Bitrix\Crm\Integration\BiConnector;

use Bitrix\BIConnector\Access\AccessController;
use Bitrix\BIConnector\Access\ActionDictionary;
use Bitrix\BIConnector\Access\Model\DashboardAccessItem;
use Bitrix\BIConnector\Configuration\Feature;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboard as SupersetDashboardRow;
use Bitrix\BIConnector\Integration\Superset\Model\SupersetDashboardTable;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\BIConnector\Superset\Dashboard\UrlParameter\Service;
use Bitrix\BIConnector\Superset\MarketAccessManager;
use Bitrix\Intranet\Settings\Tools\ToolsManager;
use Bitrix\Main\Loader;

/**
 * @internal
 */
final class Dashboard
{
	private const DELETED_STATUSES = [
		SupersetInitializer::SUPERSET_STATUS_DELETED,
		SupersetInitializer::SUPERSET_STATUS_PENDING_DELETE,
		SupersetInitializer::SUPERSET_STATUS_PENDING_DELETE_SUSPENDED,
	];

	private ?SupersetDashboardRow $row = null;
	private bool $rowLoaded = false;

	private function __construct(
		private readonly string $appId,
	)
	{
	}

	public static function create(string $appId): ?self
	{
		if (!Loader::includeModule('biconnector'))
		{
			return null;
		}

		if (in_array(SupersetInitializer::getSupersetStatus(), self::DELETED_STATUSES, true))
		{
			return null;
		}

		if (
			Loader::includeModule('intranet')
			&& !ToolsManager::getInstance()->checkAvailabilityByToolId('crm_bi')
		)
		{
			return null;
		}

		return new self($appId);
	}

	public function isFeatureEnabled(): bool
	{
		return Feature::isBuilderEnabled();
	}

	public function exists(): bool
	{
		return $this->getRow() !== null;
	}

	public function isViewableByCurrentUser(): bool
	{
		$row = $this->getRow();
		if ($row === null)
		{
			return false;
		}

		$accessItem = DashboardAccessItem::createFromArray([
			'ID' => $row->getId(),
			'TYPE' => $row->getType(),
			'STATUS' => $row->getStatus(),
		]);

		return AccessController::getCurrent()->check(
			ActionDictionary::ACTION_BIC_DASHBOARD_VIEW,
			$accessItem,
		);
	}

	public function isLockedByMarketSubscription(): bool
	{
		$row = $this->getRow();
		if ($row === null)
		{
			return false;
		}

		return !MarketAccessManager::getInstance()
			->isDashboardAvailableByType($row->getType())
		;
	}

	public function getEmbeddedUrl(string $openFrom): ?string
	{
		$row = $this->getRow();
		if ($row === null)
		{
			return null;
		}

		return (new Service($row))->getEmbeddedUrl(
			[],
			['openFrom' => $openFrom],
		);
	}

	private function getRow(): ?SupersetDashboardRow
	{
		if ($this->rowLoaded)
		{
			return $this->row;
		}

		$this->rowLoaded = true;
		$this->row = SupersetDashboardTable::query()
			->setSelect(['ID', 'TYPE', 'STATUS'])
			->where('APP_ID', $this->appId)
			->setCacheTtl(86400)
			->fetchObject()
		;

		return $this->row;
	}
}
