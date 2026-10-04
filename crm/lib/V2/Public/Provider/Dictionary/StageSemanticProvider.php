<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Provider\Dictionary;

use Bitrix\Crm\V2\Internal\Repository\Dictionary\StageSemanticRepository;
use Bitrix\Crm\V2\Public\Entity\Item\StageSemantic;

final class StageSemanticProvider
{
	private StageSemanticRepository $repository;

	public function __construct()
	{
		$this->repository = new StageSemanticRepository();
	}

	public function getById(string $id, string $languageId): ?StageSemantic
	{
		return $this->repository->getById($id, $languageId);
	}

	/**
	 * @return list<StageSemantic>
	 */
	public function getList(string $languageId): array
	{
		return $this->repository->getList($languageId);
	}
}
