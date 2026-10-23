<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Provider\Requisite;

use Bitrix\Crm\V2\Internal\Service\Requisite\BankRequisiteService;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\Provider\Requisite\Param\BankRequisiteFilter;
use Bitrix\Crm\V2\Public\Provider\Requisite\Param\BankRequisiteListContext;
use Bitrix\Crm\V2\Public\Provider\Requisite\Param\BankRequisiteSort;
use Bitrix\Main\Provider\Params\PagerInterface;
use Bitrix\Main\Result;

final class BankRequisiteProvider
{
	public const DATA_BANK_REQUISITE = 'bankRequisite';
	public const DATA_BANK_REQUISITES = 'bankRequisites';

	public function __construct(
		private readonly int $userId,
	)
	{
	}

	public function getById(EntityType $expectedOwnerType, int $id): Result
	{
		return (new BankRequisiteService($this->userId))->getById($expectedOwnerType, $id);
	}

	public function getList(
		BankRequisiteListContext $context,
		?BankRequisiteFilter $filter = null,
		?BankRequisiteSort $sort = null,
		?PagerInterface $pager = null,
	): Result
	{
		return (new BankRequisiteService($this->userId))->getList(
			$context,
			$filter,
			$sort,
			$pager,
		);
	}
}
