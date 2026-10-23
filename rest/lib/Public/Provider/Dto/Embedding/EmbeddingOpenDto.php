<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Provider\Dto\Embedding;

final class EmbeddingOpenDto
{
	public function __construct(
		public readonly int $embeddingId,
		public readonly string $url,
		public readonly array $payload = [],
	)
	{
	}
}
