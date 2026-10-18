<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Documentation;

use Bitrix\Note\Infrastructure\Rest\V3\Dto\CollectionItemDto;
use Bitrix\Rest\V3\Documentation\MethodProvider;
use Bitrix\Rest\V3\Schema\TypeAliasRegistry;

// note.collection.list returns ArrayResponse-shaped {items, nextCursor}, which the auto-generator
// renders as an empty result. This provider declares the real schema; item fields are $ref'd to
// CollectionItemDto so they stay in sync, only the envelope (items + nextCursor) is hand-written.
class CollectionListMethodProvider extends MethodProvider
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
				'pagination' => [
					'type' => 'object',
					'description' => 'Page of the list. Omit entirely to read the first page.',
					'properties' => [
						'limit' => [
							'type' => 'integer',
							'description' => 'Collections per page, capped at 200. Defaults to 50.',
						],
						'afterCursor' => [
							'type' => 'object',
							'description' =>
								'The nextCursor of the previous answer, passed back verbatim. '
								. 'Both fields are required together.'
							,
							'properties' => [
								'position' => ['type' => 'integer'],
								'id' => ['type' => 'integer'],
							],
						],
					],
				],
				'select' => [
					'type' => 'array',
					'description' =>
						'Fields to return. Omit for all of them. Narrowing the selection also skips the '
						. 'reads behind the fields left out, so it is worth doing on long lists.'
					,
					'items' => ['type' => 'string'],
				],
			],
		]]]];
	}

	protected function getResponses(): array
	{
		$itemRef = '#/components/schemas/' . TypeAliasRegistry::toPublicType(CollectionItemDto::create());

		return ['200' => ['content' => ['application/json' => [
			'schema' => [
				'type' => 'object',
				'properties' => ['result' => [
					'type' => 'object',
					'properties' => [
						'items' => ['type' => 'array', 'items' => ['$ref' => $itemRef]],
						'nextCursor' => [
							'type' => 'object',
							'nullable' => true,
							'description' => 'Null on the last page. Otherwise pass it back as pagination.afterCursor.',
							'properties' => [
								'position' => ['type' => 'integer'],
								'id' => ['type' => 'integer'],
							],
						],
					],
				]],
			],
			'example' => ['result' => [
				'items' => [[
					'id' => 123,
					'name' => 'Engineering handbook',
					'position' => 4,
					'policyLevel' => 'portal',
					'accessLevel' => 'full',
					'isArchived' => false,
					'createdBy' => 1,
					'createdAt' => '2026-08-11T10:15:00+03:00',
					'updatedBy' => 1,
					'updatedAt' => '2026-08-11T10:15:00+03:00',
					'markdownDescription' => '# Engineering handbook',
				]],
				'nextCursor' => ['position' => 4, 'id' => 123],
			]],
		]]]];
	}

	protected function getTitle(): ?string
	{
		return 'Collections visible to the caller';
	}

	protected function getDescription(): ?string
	{
		return <<<'TEXT'
			Lists the collections the caller may open, newest position first, page by page.

			Invisible collections are absent rather than refused, so an empty list is a normal answer
			and never means "no rights to use the method". Archived collections are left out as well:
			they are read through note.collection.get by their id.

			Two fields say what the caller is looking at: `policyLevel` tells whether the collection is
			open to the whole portal (`portal`) or only to the people named in its access rows
			(`private`), and `accessLevel` tells what this particular caller may do with it
			(`none` | `view` | `edit` | `full`). `accessLevel` is about the collection alone - a single
			document inside it may be granted separately and higher.
			TEXT;
	}
}
