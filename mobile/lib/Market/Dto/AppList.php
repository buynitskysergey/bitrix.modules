<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Market\Dto;

use Bitrix\Main\UI\PageNavigation;
use Bitrix\Mobile\Dto\Dto;
use Bitrix\Mobile\Market\AppListType;

final class AppList extends Dto
{
	public function __construct(
		public bool $isAvailable = false,
		public AppListType $listType = AppListType::Category,
		public string $categoryCode = '',
		public string $developerTag = '',
		public string $installedFilter = '',
		public string $title = '',
		public ?SortInfo $sortInfo = null,
		public bool $showSortMenu = false,
		public ?AppListTabs $tabs = null,
		public array $items = [],
		public ?PageNavigation $pagination = null,
		public ?array $emptyState = null,
		public ?array $unavailableState = null,
	)
	{
		$this->pagination ??= self::createPagination();

		parent::__construct();
	}

	public static function createPagination(int $currentPage = 1, int $pages = 1): PageNavigation
	{
		$pages = max(1, $pages);

		return (new PageNavigation('market_app_list'))
			->setPageSize(1)
			->setCurrentPage(max(1, $currentPage))
			->setRecordCount($pages)
		;
	}

	public function toArray(): array
	{
		$result = parent::toArray();
		$result['listType'] = $this->listType->value;
		$result['pagination'] = [
			'currentPage' => $this->pagination->getCurrentPage(),
			'pages' => $this->pagination->getPageCount(),
		];

		return $result;
	}
}
