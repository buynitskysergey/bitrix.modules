<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Grid\StorageList;

use Bitrix\Bizproc\Internal\Grid\StorageList\Column\Provider\StorageListDataProvider;
use Bitrix\Bizproc\Internal\Grid\StorageList\Row\Action;
use Bitrix\Bizproc\Internal\Grid\StorageList\Row\Assembler\StorageListRowAssembler;
use Bitrix\Main\Context;
use Bitrix\Main\Grid\Column\Columns;
use Bitrix\Main\Grid\Grid;
use Bitrix\Main\Grid\Pagination\PaginationFactory;
use Bitrix\Main\Grid\Row\Rows;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\UI\PageNavigation;

final class StorageListGrid extends Grid
{
	private const RESET_PAGINATION_PARAM = 'clear_nav';

	protected function createColumns(): Columns
	{
		return new Columns(
			new StorageListDataProvider(),
		);
	}

	protected function createRows(): Rows
	{
		return new Rows(
			new StorageListRowAssembler($this->getVisibleColumnsIds()),
			new Action\StorageListDataProvider($this->getSettings()),
		);
	}

	protected function createPagination(): ?PageNavigation
	{
		$storage = $this->getPaginationStorage();

		$pagination = (new PaginationFactory($this, $storage))->create();
		$storage?->fill($pagination);

		return $pagination;
	}

	public function processRequest(?HttpRequest $request = null): void
	{
		$request ??= Context::getCurrent()->getRequest();

		if ($request->get(self::RESET_PAGINATION_PARAM) === 'Y')
		{
			$this->resetPaginationPage();
		}

		parent::processRequest($request);
	}

	public function savePagination(PageNavigation $pagination): void
	{
		$this->getPaginationStorage()?->save($pagination);
	}

	public function switchToPageOfPosition(int $position): void
	{
		$pagination = $this->getPagination();
		if ($pagination === null)
		{
			return;
		}

		$pagination->setCurrentPage(intdiv($position, max($pagination->getPageSize(), 1)) + 1);
		$this->savePagination($pagination);
	}

	private function resetPaginationPage(): void
	{
		$pagination = $this->getPagination();
		if ($pagination === null)
		{
			return;
		}

		$pagination->setCurrentPage(1);
		$this->savePagination($pagination);
	}
}
