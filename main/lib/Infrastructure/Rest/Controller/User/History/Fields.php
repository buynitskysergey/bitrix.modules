<?php

namespace Bitrix\Main\Infrastructure\Rest\Controller\User\History;

use Bitrix\Main\Error;
use Bitrix\Main\Infrastructure\Rest\Controller\AbstractController;
use Bitrix\Main\Infrastructure\Rest\Dto\HistoryFieldDto;
use Bitrix\Main\Infrastructure\Rest\Request\User\History\FieldListRequest;
use Bitrix\Main\Infrastructure\Rest\Service\HistoryFieldsService;
use Bitrix\Main\Infrastructure\Rest\Support\LegacyQueryConverter;
use Bitrix\Rest\AccessException as LegacyAccessException;
use Bitrix\Rest\RestException as LegacyRestException;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Attribute\Scope;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;

#[DtoType(HistoryFieldDto::class)]
class Fields extends AbstractController
{
	#[Scope('user')]
	public function listAction(FieldListRequest $request, HistoryFieldsService $historyFieldsService): ListResponse
	{
		$params = [
			'filter' => LegacyQueryConverter::filterToLegacy($request->filter),
		];

		$order = LegacyQueryConverter::orderToLegacy($request->order);
		if ($order !== [])
		{
			$params['order'] = $order;
		}

		try
		{
			$result = $historyFieldsService->getList($params);
		}
		catch (LegacyAccessException)
		{
			throw new AccessDeniedException();
		}
		catch (LegacyRestException $exception)
		{
			throw new RequestValidationException([new Error($exception->getMessage(), 'filter')]);
		}

		$rawItems = [];
		foreach ($result as $item)
		{
			if (is_array($item))
			{
				$rawItems[] = $item;
			}
		}

		$rawItems = LegacyQueryConverter::sliceByPagination($rawItems, $request->pagination);
		$selectedFields = $request->select?->getStructuredList() ?? [];

		return new ListResponse($this->getDtoMapper()->mapCollection($rawItems, $selectedFields));
	}
}
