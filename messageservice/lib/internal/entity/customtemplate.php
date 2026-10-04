<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Internal\Entity;

use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\Type\DateTime;
use Bitrix\MessageService\Internal\ValueObject\CustomTemplateBinding;

final class CustomTemplate implements EntityInterface
{
	private ?int $id = null;
	private ?DateTime $dateCreate = null;
	private ?int $authorId = null;
	private ?DateTime $dateModify = null;
	private ?int $modifiedBy = null;

	public function __construct(
		private readonly CustomTemplateBinding $binding,
		private string $title,
		private string $body,
	)
	{
	}

	public function getId(): ?int
	{
		return $this->id;
	}

	public function setId(int $id): self
	{
		$this->id = $id;

		return $this;
	}

	public function getBinding(): CustomTemplateBinding
	{
		return $this->binding;
	}

	public function getTitle(): string
	{
		return $this->title;
	}

	public function setTitle(string $title): self
	{
		$this->title = $title;

		return $this;
	}

	public function getBody(): string
	{
		return $this->body;
	}

	public function setBody(string $body): self
	{
		$this->body = $body;

		return $this;
	}

	public function getDateCreate(): ?DateTime
	{
		return $this->dateCreate;
	}

	public function setDateCreate(DateTime $dateCreate): self
	{
		$this->dateCreate = $dateCreate;

		return $this;
	}

	public function getAuthorId(): ?int
	{
		return $this->authorId;
	}

	public function setAuthorId(int $authorId): self
	{
		$this->authorId = $authorId;

		return $this;
	}

	public function getDateModify(): ?DateTime
	{
		return $this->dateModify;
	}

	public function setDateModify(?DateTime $dateModify): self
	{
		$this->dateModify = $dateModify;

		return $this;
	}

	public function getModifiedBy(): ?int
	{
		return $this->modifiedBy;
	}

	public function setModifiedBy(?int $modifiedBy): self
	{
		$this->modifiedBy = $modifiedBy;

		return $this;
	}
}
