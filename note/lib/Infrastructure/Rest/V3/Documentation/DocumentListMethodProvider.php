<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Documentation;

use Bitrix\Note\Infrastructure\Rest\V3\Dto\DocumentItemDto;
use Bitrix\Note\Infrastructure\Rest\V3\Read\DocumentListQuery;
use Bitrix\Rest\V3\Documentation\MethodProvider;
use Bitrix\Rest\V3\Schema\TypeAliasRegistry;

// note.document.list answers {items, nextCursor}, which the auto-generator renders as an empty
// result. This provider declares the real schema; item fields are $ref'd to DocumentItemDto so they
// stay in sync, only the envelope and the meaning of the cursor are hand-written.
class DocumentListMethodProvider extends MethodProvider
{
	protected function getTags(): array
	{
		return ['note'];
	}

	protected function getRequestBody(): array
	{
		$maxLimit = DocumentListQuery::MAX_LIMIT;
		$defaultLimit = DocumentListQuery::DEFAULT_LIMIT;

		return ['content' => ['application/json' => ['schema' => [
			'type' => 'object',
			'properties' => [
				'filter' => [
					'type' => 'object',
					'description' =>
						'Narrows the list. `collectionId` keeps one knowledge base, and `0` keeps the '
						. 'documents that have no knowledge base at all - the ones whose base was '
						. 'deleted for good while they lay in the recycle bin. `state` accepts `=` and '
						. '`in`; omitted, it answers with the live documents alone.'
					,
					'properties' => [
						'collectionId' => ['type' => 'integer'],
						'state' => [
							'type' => 'string',
							'enum' => ['live', 'archived', 'trashed'],
						],
					],
				],
				'pagination' => [
					'type' => 'object',
					'description' => 'Page of the list. Omit entirely to read the first page.',
					'properties' => [
						'limit' => [
							'type' => 'integer',
							'description' =>
								"Documents per page, capped at {$maxLimit}. Defaults to {$defaultLimit}."
							,
						],
						'afterCursor' => [
							'type' => 'object',
							'description' =>
								'The nextCursor of the previous answer, passed back exactly as it '
								. 'arrived. Its fields are internal and may change; a cursor that was '
								. 'not issued by this method is refused.'
							,
						],
					],
				],
				'select' => [
					'type' => 'array',
					'description' =>
						'Fields to return, narrowing the answer. Omit for all of them - which is the '
						. 'metainformation of a document and nothing else. `markdown` is not one of '
						. 'them and naming it here is refused with 400: the text of a document is '
						. 'answered when that document is read, so ask note.document.get for it.'
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
					'properties' => [
						'items' => ['type' => 'array', 'items' => ['$ref' => $itemRef]],
						'nextCursor' => [
							'type' => 'object',
							'nullable' => true,
							'description' =>
								'Null on the last page, and the only thing that says the walk is '
								. 'over: an answer whose items are empty while this is not null is '
								. 'a normal one and means "nothing on this stretch, carry on from '
								. 'here". Otherwise pass it back as pagination.afterCursor, '
								. 'unchanged.'
							,
						],
					],
				]],
			],
			'example' => ['result' => [
				'items' => [[
					'id' => 42,
					'collectionId' => 123,
					'parentId' => 10,
					'title' => 'Release notes',
					'position' => 2,
					'createdBy' => 1,
					'updatedBy' => 1,
					'createdAt' => '2026-08-11T10:15:00+03:00',
					'updatedAt' => '2026-08-11T10:15:00+03:00',
					'contentUpdatedAt' => '2026-08-11T11:02:00+03:00',
					'state' => 'live',
					'isArchived' => false,
					'isTrashed' => false,
					'isOrphan' => false,
				]],
				'nextCursor' => ['stream' => 'collections', 'collectionId' => 123, 'id' => 42],
			]],
		]]]];
	}

	protected function getTitle(): ?string
	{
		return 'Documents visible to the caller';
	}

	protected function getDescription(): ?string
	{
		return <<<'TEXT'
			Lists every document the caller may read, page by page, across all their knowledge bases -
			including the documents granted to them one by one inside bases they cannot otherwise open.

			Invisible documents are absent rather than refused, so an empty list is a normal answer and
			never means "no rights to use the method". A `collectionId` naming a base the caller may not
			open is answered as a missing entity, the same way note.collection.get answers about it.

			Without a `state` in the filter the answer holds the live documents alone, which is what a
			client that knows nothing about archives and recycle bins expects. Ask for more by name:
			`state` with `in` takes several at once. A trashed document of a base that is itself in the
			recycle bin is answered only to whoever may moderate that base.

			Pages are walked by cursor and not by offset: read the first page without `pagination`,
			then pass the `nextCursor` of every answer back as `pagination.afterCursor` until it comes
			back null. A null cursor is the only end of the walk: an answer may hold an empty `items`
			while its `nextCursor` is set, and it means "nothing on this stretch, carry on from here"
			rather than "no more documents" - the page is assembled from several streams of visibility
			and a call reads only so far, so a long run of documents the caller cannot see can use that
			up before a single row of the page is found. A loop that stops on an empty page loses
			everything behind it. The cursor is opaque - store it and return it as it arrived, and do
			not build one by hand. The order the documents come in is an internal matter as well: what the method
			promises is that a walk started and finished with these cursors names every visible document
			once, not that a particular document comes before another. A document deleted between two
			pages simply stops being named.

			The answer is metainformation and not content: a page holds what a client needs to show a
			list and decide what to open - identifiers, title, state, dates, author, base - and not the
			text of the documents. That is a rule of the module rather than a default of this method:
			the text of a document is answered when that document is read, and `markdown` is refused
			with 400 when a `select` names it here. A page of two hundred texts is hundreds of
			megabytes, and a promise of that size is kept as a timeout or a truncated body rather than
			as an answer. Read the text one document at a time with note.document.get.

			This method is about which documents exist. For the shape of one base - what is nested under
			what - use note.document.tree.list, and to find a document by its text, note.document.search.list.
			TEXT;
	}
}
