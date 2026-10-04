<?php

namespace Bitrix\Main\Infrastructure\Rest\Controller;

use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Infrastructure\Rest\Dto\LikeItemDto;
use Bitrix\Main\Infrastructure\Rest\Request\LikeListRequest;
use Bitrix\Main\Infrastructure\Rest\Response\LikeListResponse;
use Bitrix\Main\Infrastructure\Rest\Service\LikeListService;
use Bitrix\Rest\RestException as LegacyRestException;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Attribute\Scope;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Interaction\Response\ArrayResponse;
use Bitrix\Rest\V3\Structure\PaginationStructure;

#[DtoType(LikeItemDto::class)]
class Like extends AbstractController
{
	#[Scope('rating')]
	public function listAction(LikeListRequest $request, LikeListService $likeListService): LikeListResponse
	{
		$filter = $request->filter->getSimpleFilterConditions();
		$entityTypeId = (string)($filter['entityTypeId'] ?? '');
		$entityId = (int)($filter['entityId'] ?? 0);
		if ($entityTypeId === '' || $entityId <= 0)
		{
			throw new RequestValidationException([
				new Error('entityTypeId must be non-empty and entityId must be positive.', 'filter'),
			]);
		}

		$pathToUserProfile = (string)($filter['pathToUserProfile'] ?? '');
		$reaction = isset($filter['reaction']) ? (string)$filter['reaction'] : null;
		if ($reaction === '')
		{
			$reaction = null;
		}

		$limit = $request->pagination ? $request->pagination->getLimit() : PaginationStructure::DEFAULT_LIMIT;
		$offset = $request->pagination ? $request->pagination->getOffset() : 0;
		$skip = $offset % $limit;
		$page = (int)floor($offset / $limit) + 1;
		$currentUser = CurrentUser::get();
		$viewerUserId = $currentUser->getId() > 0 ? (int)$currentUser->getId() : null;

		$pageResult = $likeListService->getList(
			entityTypeId: $entityTypeId,
			entityId: $entityId,
			page: $page,
			limit: $limit,
			pathToUserProfile: $pathToUserProfile,
			reaction: $reaction,
			viewerUserId: $viewerUserId,
		);

		$items = $pageResult['items'];
		if ($skip > 0)
		{
			$nextPageResult = $likeListService->getList(
				entityTypeId: $entityTypeId,
				entityId: $entityId,
				page: $page + 1,
				limit: $limit,
				pathToUserProfile: $pathToUserProfile,
				reaction: $reaction,
				viewerUserId: null,
			);
			$items = array_slice(
				array_merge($pageResult['items'], $nextPageResult['items']),
				$skip,
				$limit,
			);
		}

		$rawItems = [];
		foreach ($items as $item)
		{
			$rawItems[] = [
				...$item,
				'ENTITY_TYPE_ID' => $entityTypeId,
				'ENTITY_ID' => $entityId,
				'PATH_TO_USER_PROFILE' => $pathToUserProfile !== '' ? $pathToUserProfile : null,
				'REACTION' => $reaction,
			];
		}

		$selectedFields = $request->select?->getStructuredList() ?? [];

		return new LikeListResponse(
			items: $this->getDtoMapper()->mapCollection($rawItems, $selectedFields),
			total: $pageResult['total'],
		);
	}

	#[Scope('rating')]
	public function reactionsAction(string $entityTypeId, int $entityId, LikeListService $likeListService): ArrayResponse
	{
		try
		{
			$result = $likeListService->getReactions($entityTypeId, $entityId);
		}
		catch (LegacyRestException $exception)
		{
			throw new RequestValidationException([new Error($exception->getMessage())]);
		}

		return new ArrayResponse($result);
	}
}
