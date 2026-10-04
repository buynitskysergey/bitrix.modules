<?php

namespace Bitrix\Main\Infrastructure\Rest\Controller;

use Bitrix\Main\Infrastructure\Rest\Dto\SmileSetDto;
use Bitrix\Main\Infrastructure\Rest\Support\LegacyQueryConverter;
use Bitrix\Main\Infrastructure\Rest\Support\SmileCatalog;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Attribute\Scope;
use Bitrix\Rest\V3\Interaction\Request\ListRequest;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;

#[DtoType(SmileSetDto::class)]
class SmileSet extends AbstractController
{
	#[Scope('smile')]
	public function listAction(ListRequest $request): ListResponse
	{
		$catalog = SmileCatalog::fetch();
		$items = LegacyQueryConverter::sliceByPagination(
			$catalog['sets'] ?? [],
			$request->pagination,
		);
		$selectedFields = $request->select?->getStructuredList() ?? [];

		return new ListResponse(
			$this->getDtoMapper()->mapCollection($items, $selectedFields),
		);
	}
}
