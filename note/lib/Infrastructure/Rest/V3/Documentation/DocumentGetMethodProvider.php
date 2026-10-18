<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Documentation;

use Bitrix\Note\Infrastructure\Rest\V3\Dto\DocumentItemDto;
use Bitrix\Rest\V3\Documentation\MethodProvider;
use Bitrix\Rest\V3\Schema\TypeAliasRegistry;

// note.document.get answers with a stock GetResponse, so the auto-generator would describe it well
// enough on its own. What it cannot describe is the point of the state markers and the price of the
// text: both are decisions of the method, not shapes of the DTO.
class DocumentGetMethodProvider extends MethodProvider
{
	protected function getTags(): array
	{
		return ['note'];
	}

	protected function getRequestBody(): array
	{
		return ['content' => ['application/json' => ['schema' => [
			'type' => 'object',
			'required' => ['id'],
			'properties' => [
				'id' => [
					'type' => 'integer',
					'description' => 'Id of the document. One the caller may not read is answered as a missing one.',
				],
				'select' => [
					'type' => 'array',
					'description' =>
						'Fields to return. Omit for all of them, `markdown` included. A selection '
						. 'without `markdown` skips building the text altogether, which is the one '
						. 'expensive part of the answer.'
					,
					'items' => ['type' => 'string'],
				],
			],
		]]]];
	}

	protected function getResponses(): array
	{
		$itemRef = '#/components/schemas/' . TypeAliasRegistry::toPublicType(DocumentItemDto::create());

		return ['200' => ['content' => ['application/json' => [
			'schema' => [
				'type' => 'object',
				'properties' => ['result' => [
					'type' => 'object',
					'properties' => ['item' => ['$ref' => $itemRef]],
				]],
			],
			'example' => ['result' => ['item' => [
				'id' => 42,
				'collectionId' => 123,
				'parentId' => 10,
				'title' => 'Release notes',
				'markdown' => "# Release notes\n\nThe notes of the last sprint.",
				'position' => 2,
				'createdBy' => 1,
				'updatedBy' => 1,
				'createdAt' => '2026-08-11T10:15:00+03:00',
				'updatedAt' => '2026-08-11T10:15:00+03:00',
				'contentUpdatedAt' => '2026-08-11T11:02:00+03:00',
				'isArchived' => false,
				'isTrashed' => false,
				'isOrphan' => false,
			]]],
		]]]];
	}

	protected function getTitle(): ?string
	{
		return 'One document with its text and its state';
	}

	protected function getDescription(): ?string
	{
		return <<<'TEXT'
			Reads a single document: its record, its markdown, and what state it is in.

			Archived and trashed documents answer here as well - they are readable, only writes into
			them are refused. Which one the caller is holding is said by three markers: `isArchived`
			(the document is in the archive), `isTrashed` (it is in the recycle bin) and `isOrphan`
			(meaningful only together with `isTrashed`: the collection that owned it is gone, so
			restoring it needs a new collection to restore into).

			`updatedAt` moves on a structural change of the record - a rename, a move, an archive, an
			overwrite through REST. An edit in the collaborative editor deliberately moves
			`contentUpdatedAt` instead, so a client watching for text changes should watch that field.

			`markdown` is not the live document: it is a projection built from the editor's own format,
			and `contentUpdatedAt` is the moment that projection was built, not the moment somebody last
			typed. A `null` there means the projection has never been built and the markdown is empty
			for that reason alone. For an archived or a trashed document the moment is final - nothing
			is going to rebuild it.

			The text is markdown, and it comes escaped: outside code blocks and code spans the
			characters `\` `` ` `` `*` `_` `[` `]` `~` carry a backslash in front of them, and inside
			a table cell the vertical bar does as well. A caller that shows the field to a human is
			expected to parse it as markdown; one that matches it against plain text should drop those
			backslashes first, or `some\_var\_name` will not match `some_var_name`.

			The main document of a collection is not addressable here: its text is the collection's own
			description, and it is read as `markdownDescription` of note.collection.get.
			TEXT;
	}
}
