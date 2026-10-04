<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Provider\Dictionary;

use Bitrix\Crm\V2\Internal\Repository\Dictionary\AddressTypeRepository;
use Bitrix\Crm\V2\Public\Entity\Item\AddressType;

final class AddressTypeProvider
{
	private AddressTypeRepository $repository;

	public function __construct()
	{
		$this->repository = new AddressTypeRepository();
	}

	public function getById(int $id, string $languageId): ?AddressType
	{
		return $this->repository->getById($id, $languageId);
	}

	/**
	 * @return list<AddressType>
	 */
	public function getList(string $languageId): array
	{
		return $this->repository->getList($languageId);
	}
}
