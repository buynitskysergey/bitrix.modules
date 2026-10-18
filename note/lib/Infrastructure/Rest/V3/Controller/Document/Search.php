<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Controller\Document;

use Bitrix\Main\Error;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Note\Public\Provider\Param\Search\SearchLimits;
use Bitrix\Note\Public\Provider\Param\Search\SearchQuery;
use Bitrix\Note\Public\Provider\SearchProvider;
use Bitrix\Note\Infrastructure\Rest\V3\Controller\ActionFilter\NoteRestAccess;
use Bitrix\Note\Infrastructure\Rest\V3\Dto\SearchResultItemDto;
use Bitrix\Note\Infrastructure\Rest\V3\Response\DocumentSearchListResponse;
use Bitrix\Note\Infrastructure\Rest\V3\Structure\SearchPaginationStructure;
use Bitrix\Note\Infrastructure\Rest\V3\Exceptions\SearchQueryTooLongException;
use Bitrix\Note\Infrastructure\Rest\V3\Exceptions\SearchQueryTooShortException;
use Bitrix\Note\Infrastructure\Rest\V3\Request\SearchDocumentsRequest;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;

#[DtoType(SearchResultItemDto::class)]
class Search extends RestController
{
	protected function getDefaultPreFilters(): array
	{
		return [
			...parent::getDefaultPreFilters(),
			new NoteRestAccess(),
		];
	}

	public function listAction(SearchDocumentsRequest $request): DocumentSearchListResponse
	{
		$filter = $request->filter?->getSimpleFilterConditions() ?? [];

		// The filter wins when both are given: a client in the middle of the migration may still send
		// the old parameter alongside the new one, and refusing that would break it for no gain.
		$raw = trim((string)($filter['query'] ?? $request->query ?? ''));
		if ($raw === '')
		{
			throw new RequestValidationException([
				new Error('The search string is required: pass filter.query.', 'filter'),
			]);
		}

		$collectionId = isset($filter['collectionId']) ? (int)$filter['collectionId'] : null;
		$length = mb_strlen($raw);

		if ($length < SearchLimits::MIN_TOKEN_LENGTH)
		{
			throw new SearchQueryTooShortException(SearchLimits::MIN_TOKEN_LENGTH);
		}
		if ($length > SearchLimits::MAX_QUERY_LENGTH)
		{
			throw new SearchQueryTooLongException(SearchLimits::MAX_QUERY_LENGTH);
		}

		$limit = ($request->pagination ?? new SearchPaginationStructure())->getLimit();
		$fields = $request->select?->getStructuredList() ?? [];
		// A snippet costs the whole body of every row on the page: it is selected, decoded out of its
		// emoji storage and cut around the tokens. A caller that asked for titles alone should not pay
		// that bill only to have the mapper drop the result. An empty select means every field.
		$withSnippets = $fields === [] || in_array('snippet', $fields, true);

		$collection = (new SearchProvider())->search(
			query: new SearchQuery($raw),
			pager: new Pager($limit),
			withSnippets: $withSnippets,
			collectionId: $collectionId,
		);

		$results = [];
		foreach ($collection as $result)
		{
			$results[] = $result;
		}

		return new DocumentSearchListResponse(
			$this->getDtoMapper()->mapCollection($results, $fields),
			$collection->hasMore(),
		);
	}
}
