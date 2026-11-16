<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Documentation;

use Bitrix\Note\Infrastructure\Rest\V3\Dto\FileItemDto;
use Bitrix\Note\Infrastructure\Rest\V3\Request\ListFilesRequest;
use Bitrix\Rest\V3\Documentation\MethodProvider;
use Bitrix\Rest\V3\Schema\TypeAliasRegistry;

// note.file.list answers {items, nextCursor}, which the auto-generator renders as an empty result.
// This provider declares the real schema; item fields are $ref'd to FileItemDto so they stay in
// sync, only the envelope and the meaning of the cursor are hand-written.
class FileListMethodProvider extends MethodProvider
{
	protected function getTags(): array
	{
		return ['note'];
	}

	protected function getRequestBody(): array
	{
		$maxLimit = ListFilesRequest::MAX_LIMIT;
		$defaultLimit = ListFilesRequest::DEFAULT_LIMIT;

		return ['content' => ['application/json' => ['schema' => [
			'type' => 'object',
			'required' => ['documentId'],
			'properties' => [
				'documentId' => [
					'type' => 'integer',
					'description' => 'The document whose attachments to list.',
				],
				'pagination' => [
					'type' => 'object',
					'description' => 'Page of the list. Omit entirely to read the first page.',
					'properties' => [
						'limit' => [
							'type' => 'integer',
							'description' =>
								"Attachments per page, capped at {$maxLimit}. Defaults to {$defaultLimit}."
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
			],
		]]]];
	}

	protected function getResponses(): array
	{
		$itemRef = '#/components/schemas/' . TypeAliasRegistry::toPublicType(FileItemDto::create());

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
								'Null on the last page. Otherwise pass it back as '
								. 'pagination.afterCursor, unchanged.'
							,
						],
					],
				]],
			],
			'example' => ['result' => [
				'items' => [[
					'id' => 77,
					'documentId' => 42,
					'name' => 'diagram.png',
					'size' => 20481,
					'mimeType' => 'image/png',
					'assetType' => 'image',
					'assetMarkdown' => '[[image fileId=77]]',
					'createdAt' => '2026-08-11T10:15:00+03:00',
				]],
				'nextCursor' => ['id' => 77],
			]],
		]]]];
	}

	protected function getTitle(): ?string
	{
		return 'Attachments of a document';
	}

	protected function getDescription(): ?string
	{
		return <<<'TEXT'
			Lists the files attached to a document: the same record note.file.get answers with, plus
			`createdAt` - the moment the file was attached.

			That date is the one the background cleanup goes by. A file uploaded through
			note.file.add and never pasted into the text of the document is swept away once it is old
			enough, so `createdAt` is what tells a client how long an attachment it has not used yet
			still has.

			Whether a file is actually pasted into the text is deliberately not reported: the text of
			the document comes with note.document.get, and the client that needs to know can look for
			the `assetMarkdown` of the file in it.

			Listing follows the document rather than the right to write it. An archived or trashed
			document the caller may still open names its attachments the same way a live one does,
			and a document the caller may not see is answered as a missing entity - the same answer a
			made up identifier gets.

			Pages are walked by cursor and not by offset: read the first page without `pagination`,
			then pass the `nextCursor` of every answer back as `pagination.afterCursor` until it
			comes back null. The cursor is opaque - store it and return it as it arrived, and do not
			build one by hand.
			TEXT;
	}
}
