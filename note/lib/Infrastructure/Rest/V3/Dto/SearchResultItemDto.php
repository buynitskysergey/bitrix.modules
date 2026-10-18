<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Dto;

use Bitrix\Note\Infrastructure\Rest\V3\Dto\Mapping\SearchResultMapper;
use Bitrix\Rest\V3\Attribute\Filterable;
use Bitrix\Rest\V3\Attribute\MappedBy;
use Bitrix\Rest\V3\Dto\Dto;

#[MappedBy(SearchResultMapper::class)]
class SearchResultItemDto extends Dto
{
	public ?int $documentId;

	#[Filterable]
	public ?int $collectionId;

	// Selection only, never part of an answer: the canon takes filter fields from the DTO of the
	// method, and the search string is a filter field. The mapper never fills it, and an unfilled
	// property is left out of the response.
	#[Filterable]
	public ?string $query;

	public ?string $title;
	public ?float $score;
	public ?string $snippet;
	public ?bool $sharedAccess;
}
