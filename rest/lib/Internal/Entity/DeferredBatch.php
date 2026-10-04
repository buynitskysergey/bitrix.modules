<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Entity;

use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\Type\DateTime;
use Bitrix\Rest\Internal\Entity\DeferredBatch\Status;
use Bitrix\Rest\Internal\Model\DeferredBatch\Params;

class DeferredBatch implements EntityInterface
{
	public function __construct(
		private ?int $id = null,
		private ?int $userId = null,
		private ?Status $status = null,
		private ?array $params = null,
		private ?array $commands = null,
		private ?array $scopes = null,
		private ?int $resultFileId = null,
		private ?string $errorMessage = null,
		private ?DateTime $createdAt = null,
		private ?DateTime $updatedAt = null,
	)
	{
	}

	public function getId(): ?int
	{
		return $this->id;
	}

	public function setId(?int $id): static
	{
		$this->id = $id;

		return $this;
	}

	public function getUserId(): ?int
	{
		return $this->userId;
	}

	public function setUserId(?int $userId): static
	{
		$this->userId = $userId;

		return $this;
	}

	public function getStatus(): ?Status
	{
		return $this->status;
	}

	public function setStatus(?Status $status): static
	{
		$this->status = $status;

		return $this;
	}

	public function getParams(): ?array
	{
		return $this->params;
	}

	public function setParams(?array $params): static
	{
		if ($params === null)
		{
			$this->params = null;

			return $this;
		}

		foreach ($params as $index => $param)
		{
			if (Params::tryFrom($index) === null)
			{
				unset($params[$index]); // ignore unknown params
			}
		}

		$this->params = $params;

		return $this;
	}

	public function getCommands(): ?array
	{
		return $this->commands;
	}

	public function setCommands(?array $commands): static
	{
		$this->commands = $commands;

		return $this;
	}

	public function getScopes(): ?array
	{
		return $this->scopes;
	}

	public function setScopes(?array $scopes): static
	{
		$this->scopes = $scopes;

		return $this;
	}

	public function getResultFileId(): ?int
	{
		return $this->resultFileId;
	}

	public function setResultFileId(?int $resultFileId): static
	{
		$this->resultFileId = $resultFileId;

		return $this;
	}

	public function getErrorMessage(): ?string
	{
		return $this->errorMessage;
	}

	public function setErrorMessage(?string $errorMessage): static
	{
		$this->errorMessage = $errorMessage;

		return $this;
	}

	public function getCreatedAt(): ?DateTime
	{
		return $this->createdAt;
	}

	public function setCreatedAt(?DateTime $createdAt): static
	{
		$this->createdAt = $createdAt;

		return $this;
	}

	public function getUpdatedAt(): ?DateTime
	{
		return $this->updatedAt;
	}

	public function setUpdatedAt(?DateTime $updatedAt): static
	{
		$this->updatedAt = $updatedAt;

		return $this;
	}
}

