<?php

namespace Bitrix\Main\Infrastructure\Rest\Controller;

use Bitrix\Main\Infrastructure\Rest\Dto\SmileDto;
use Bitrix\Main\Infrastructure\Rest\Request\SmileListRequest;
use Bitrix\Main\Infrastructure\Rest\Support\LegacyQueryConverter;
use Bitrix\Main\Infrastructure\Rest\Support\SmileCatalog;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Attribute\Scope;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;

#[DtoType(SmileDto::class)]
class Smile extends AbstractController
{
	#[Scope('smile')]
	public function listAction(SmileListRequest $request): ListResponse
	{
		$catalog = SmileCatalog::fetch($request->fullTypings === true);
		$items = LegacyQueryConverter::sliceByPagination(
			$catalog['smiles'] ?? [],
			$request->pagination,
		);
		$selectedFields = $request->select?->getStructuredList() ?? [];

		return new ListResponse(
			$this->getDtoMapper()->mapCollection($items, $selectedFields),
		);
	}
}
