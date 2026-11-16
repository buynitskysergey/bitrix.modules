<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Request;

use Bitrix\Main\Error;
use Bitrix\Note\Infrastructure\Rest\V3\Read\DocumentListQuery;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Interaction\Request\Request;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\SelectStructure;

class ListDocumentsRequest extends Request
{
	public ?SelectStructure $select = null;

	public ?FilterStructure $filter = null;

	/**
	 * Page of the list: `limit` and `afterCursor`, the cursor of the previous answer passed back
	 * verbatim. Read as it arrives rather than through a Structure of its own - the cursor is opaque
	 * to the client, and there is nothing in it to validate against the DTO. Its numbers are still
	 * numbers, which is the one thing checked here ({@see PaginationNumber}).
	 */
	public ?array $pagination = null;

	/**
	 * Fields of the answer, with the text of the documents refused rather than dropped.
	 *
	 * The list answers with metainformation and never with content, and a caller who names the text
	 * anyway has to hear it: a page silently short of the field reads as "these documents are empty",
	 * and an integration written against that finding works on the short articles of a test portal
	 * and falls over on a real one. Refusing here also keeps the promise cheap to keep - a page of
	 * two hundred texts is hundreds of megabytes that would break as a timeout or a truncated body
	 * rather than as an answer.
	 *
	 * @return string[]|array<string, mixed> empty when the caller named nothing
	 * @throws RequestValidationException when the select names a field the list never answers with
	 */
	public function getSelect(): array
	{
		$select = $this->select?->getStructuredList() ?? [];

		foreach (DocumentListQuery::HEAVY_FIELDS as $field)
		{
			if (in_array($field, $select, true) || array_key_exists($field, $select))
			{
				throw new RequestValidationException([
					new Error(
						$field . ' is not a field of this list: it answers with the metainformation of a '
						. 'document and never with its text. Read the text of one document with '
						. 'note.document.get.',
						'select',
					),
				]);
			}
		}

		return $select;
	}

	public function getLimit(): int
	{
		$limit = PaginationNumber::read($this->pagination['limit'] ?? null, 'pagination.limit');

		return $limit > 0 ? min($limit, DocumentListQuery::MAX_LIMIT) : DocumentListQuery::DEFAULT_LIMIT;
	}

	public function getAfterCursor(): ?array
	{
		$cursor = $this->pagination['afterCursor'] ?? null;

		return is_array($cursor) ? $cursor : null;
	}
}
