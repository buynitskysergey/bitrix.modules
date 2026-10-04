<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Entity\Search;

use Bitrix\Main\Entity\EntityInterface;

final class SearchResult implements EntityInterface, \JsonSerializable
{
	public function __construct(
		private readonly int $documentId,
		private readonly int $collectionId,
		private readonly string $title,
		private readonly float $score,
		private readonly string $snippet = '',
		private readonly bool $sharedAccess = false,
		private readonly string $collectionTitle = '',
		private readonly ?array $author = null,
	) {}

	public function getId(): int
	{
		return $this->documentId;
	}

	public function getDocumentId(): int
	{
		return $this->documentId;
	}

	public function getCollectionId(): int
	{
		return $this->collectionId;
	}

	public function getTitle(): string
	{
		return $this->title;
	}

	public function getScore(): float
	{
		return $this->score;
	}

	public function getSnippet(): string
	{
		return $this->snippet;
	}

	public function isSharedAccess(): bool
	{
		return $this->sharedAccess;
	}

	public function getCollectionTitle(): string
	{
		return $this->collectionTitle;
	}

	/**
	 * @return array{id: int, name: string, photoUrl: ?string, color?: string}|null
	 */
	public function getAuthor(): ?array
	{
		return $this->author;
	}

	/**
	 * @param array{id: int, name: string, photoUrl: ?string, color?: string}|null $author
	 */
	public function withMeta(string $collectionTitle, ?array $author): self
	{
		return new self(
			$this->documentId,
			$this->collectionId,
			$this->title,
			$this->score,
			$this->snippet,
			$this->sharedAccess,
			$collectionTitle,
			$author,
		);
	}

	public function jsonSerialize(): array
	{
		$payload = [
			'documentId' => $this->documentId,
			'title' => $this->title,
			'score' => $this->score,
			'snippet' => $this->snippet,
			'sharedAccess' => $this->sharedAccess,
			'author' => $this->author,
		];

		if (!$this->sharedAccess)
		{
			$payload['collectionId'] = $this->collectionId;
			$payload['collectionTitle'] = $this->collectionTitle;
		}

		return $payload;
	}
}
