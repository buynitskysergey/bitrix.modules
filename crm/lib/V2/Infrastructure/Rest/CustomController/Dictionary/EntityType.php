<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Dictionary;

use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\AbstractController;
use Bitrix\Crm\V2\Infrastructure\Rest\Data\Dictionary\EntityTypeRepository;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Dictionary\EntityTypeDto;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Dictionary\EntityTypeMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Dictionary\EntityType\GetRequest;
use Bitrix\Crm\V2\Public\Provider\Dictionary\EntityTypeProvider;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Exception\EntityNotFoundException;
use Bitrix\Rest\V3\Interaction\Request\ListRequest;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;

#[DtoType(EntityTypeDto::class)]
final class EntityType extends AbstractController
{
	private ?EntityTypeRepository $repository = null;

	public function getAction(GetRequest $request): GetResponse
	{
		$dto = $this->getRepository()->getOneWith(
			$request->select,
			(new FilterStructure())->where('id', $request->id),
		);
		if ($dto === null)
		{
			throw new EntityNotFoundException($request->id);
		}

		return new GetResponse($dto);
	}

	public function listAction(ListRequest $request): ListResponse
	{
		return new ListResponse(
			$this->getRepository()->getAll(
				$request->select,
				$request->filter,
				$request->order,
				$request->pagination,
			),
		);
	}

	private function getRepository(): EntityTypeRepository
	{
		return $this->repository ??= new EntityTypeRepository(
			new EntityTypeProvider(),
			new EntityTypeMapper(),
			$this->getResponseLanguage(),
		);
	}
}
