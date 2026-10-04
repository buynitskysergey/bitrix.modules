<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Item\FieldValueElement;

final readonly class FileUploadValue
{
	public function __construct(
		private string $name,
		private ?string $data,
		private ?string $url,
		private string $path,
	)
	{
	}

	public function getName(): string
	{
		return $this->name;
	}

	public function getData(): ?string
	{
		return $this->data;
	}

	public function getUrl(): ?string
	{
		return $this->url;
	}

	public function getPath(): string
	{
		return $this->path;
	}
}
