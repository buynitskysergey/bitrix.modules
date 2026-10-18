<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Documentation;

use Bitrix\Note\Infrastructure\Rest\V3\Dto\DocumentTreeItemDto;
use Bitrix\Note\Public\Provider\TreeProvider;
use Bitrix\Rest\V3\Documentation\MethodProvider;
use Bitrix\Rest\V3\Schema\TypeAliasRegistry;

// note.document.tree.list returns a nested tree {items: [nodes with children]}.
// items $ref DocumentTreeItemDto, whose recursive children is fixed up by
// DocumentTreeNodeSchemaProvider; the example shows the concrete nesting.
class DocumentTreeListMethodProvider extends MethodProvider
{
	protected function getTags(): array
	{
		return ['note'];
	}

	protected function getRequestBody(): array
	{
		return ['content' => ['application/json' => ['schema' => [
			'type' => 'object',
			'required' => ['collectionId'],
			'properties' => [
				'collectionId' => [
					'type' => 'integer',
					'description' => 'The collection whose tree is read. A collection the caller may not open is answered as a missing one.',
				],
			],
		]]]];
	}

	protected function getResponses(): array
	{
		$itemRef = '#/components/schemas/' . TypeAliasRegistry::toPublicType(DocumentTreeItemDto::create());

		return ['200' => ['content' => ['application/json' => [
			'schema' => [
				'type' => 'object',
				'properties' => ['result' => [
					'type' => 'object',
					'properties' => [
						'items' => ['type' => 'array', 'items' => ['$ref' => $itemRef]],
					],
				]],
			],
			'example' => ['result' => [
				'items' => [[
					'id' => 10,
					'collectionId' => 123,
					'parentId' => null,
					'title' => 'Introduction',
					'position' => 1,
					'children' => [[
						'id' => 11,
						'collectionId' => 123,
						'parentId' => 10,
						'title' => 'Chapter 1',
						'position' => 1,
						'children' => [],
					]],
				]],
			]],
		]]]];
	}

	protected function getTitle(): ?string
	{
		return 'Document tree of a collection';
	}

	protected function getDescription(): ?string
	{
		$maxNodes = TreeProvider::TREE_MAX_NODES;

		return <<<TEXT
			Returns the documents of one collection nested into a tree, in one answer.

			The answer is whole or it is a refusal: a collection holding more than
			{$maxNodes} documents is answered with NOTE_TREE_TOO_LARGE rather than with a part of
			itself, because a caller cannot tell which branches a cut tree is missing.

			The tree carries the living documents only: the main document of the collection, the
			archived ones and everything in the recycle bin are left out of it. Use note.document.get
			to read any of those by id - it answers for archived and trashed documents too, and reports
			what they are in `isArchived` / `isTrashed` / `isOrphan`.

			This method is about the shape of one collection. To find a document across collections by
			its text, use note.document.search.list instead.
			TEXT;
	}
}
