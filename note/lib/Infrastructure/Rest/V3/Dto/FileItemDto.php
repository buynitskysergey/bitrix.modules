<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Dto;

use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\V3\Dto\Dto;

class FileItemDto extends Dto
{
	public ?int $id;
	public ?int $documentId;
	public ?string $name;
	public ?int $size;
	public ?string $mimeType;
	public ?string $assetType;
	public ?string $assetMarkdown;

	// When the file was attached to the document. Read from the link row, so only the list answers
	// with it - the single reads leave it uninitialized and it stays out of their answer. This is
	// the age the background cleanup measures an abandoned upload by.
	public ?DateTime $createdAt;
}
