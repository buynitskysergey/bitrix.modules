<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Documentation;

use Bitrix\Note\Infrastructure\Rest\V3\Dto\SearchResultItemDto;
use Bitrix\Note\Public\Provider\Param\Search\SearchLimits;
use Bitrix\Rest\V3\Documentation\MethodProvider;
use Bitrix\Rest\V3\Schema\TypeAliasRegistry;

// note.document.search.list returns {items, hasMore}; declares the real schema for the
// otherwise-empty result. Item fields are $ref'd to SearchResultItemDto.
class DocumentSearchListMethodProvider extends MethodProvider
{
	protected function getTags(): array
	{
		return ['note'];
	}

	protected function getRequestBody(): array
	{
		return ['content' => ['application/json' => ['schema' => [
			'type' => 'object',
			'properties' => [
				'filter' => [
					'type' => 'array',
					'description' =>
						'Selection, as pairs of field and value: `query` - the search string (required), '
						. '`collectionId` - the collection to search in. Example: '
						. '[["query", "release notes"], ["collectionId", 123]].'
					,
					'items' => ['type' => 'array'],
				],
				'query' => [
					'type' => 'string',
					'deprecated' => true,
					'description' =>
						'The search string as a parameter of its own. Superseded by filter.query and '
						. 'kept only so integrations do not break mid-migration; the filter wins when '
						. 'both are given. Move to the filter before this parameter is removed.'
					,
				],
				'pagination' => [
					'type' => 'object',
					'properties' => [
						'limit' => [
							'type' => 'integer',
							'description' => 'Results per page, capped at 200. Defaults to 50.',
						],
					],
				],
				'select' => [
					'type' => 'array',
					'description' => 'Fields of the result item to return. Omit for all of them.',
					'items' => ['type' => 'string'],
				],
			],
		]]]];
	}

	protected function getResponses(): array
	{
		$itemRef = '#/components/schemas/' . TypeAliasRegistry::toPublicType(SearchResultItemDto::create());

		return ['200' => ['content' => ['application/json' => [
			'schema' => [
				'type' => 'object',
				'properties' => ['result' => [
					'type' => 'object',
					'properties' => [
						'items' => ['type' => 'array', 'items' => ['$ref' => $itemRef]],
						'hasMore' => [
							'type' => 'boolean',
							'description' => 'True when the answer was cut by the limit. A bigger limit reaches '
								. 'further, but only as far as 200: search has neither a cursor nor an offset, so past '
								. 'that the remainder is reachable only by asking a narrower question.',
						],
					],
				]],
			],
			'example' => ['result' => [
				'items' => [[
					'documentId' => 42,
					'collectionId' => 123,
					'title' => 'Release notes',
					'score' => 3.7,
					'snippet' => 'The <mark>release</mark> notes of the last sprint',
					'sharedAccess' => false,
				]],
				'hasMore' => false,
			]],
		]]]];
	}

	protected function getTitle(): ?string
	{
		return 'Full-text search over documents';
	}

	protected function getDescription(): ?string
	{
		$minToken = SearchLimits::MIN_TOKEN_LENGTH;

		return <<<TEXT
			Searches the content and titles of the documents the caller may read.

			What is invisible in the listings is not found here either: search and lists are built on
			one and the same visibility rule, so a document of a foreign collection exists, is indexed,
			and stays out of the answer. A collection named in the filter that the caller may not open
			is answered with an empty list rather than a refusal - for the same reason.

			`sharedAccess` marks a hit the caller reaches through a grant on the document itself rather
			than through the collection: the document is readable, the collection around it is not.

			A search string shorter than {$minToken} characters is refused: the index is built on tokens
			of at least that length, so a shorter string would silently match nothing.
			TEXT;
	}
}
