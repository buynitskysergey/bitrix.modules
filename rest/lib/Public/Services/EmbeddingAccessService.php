<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Services;

use Bitrix\Rest\Internal\Repository\EmbeddingAccessRepository;
use Bitrix\Rest\Internal\Service\Embedding\EmbeddingAccessCodeNormalizer;

class EmbeddingAccessService
{
	public function __construct(
		private readonly EmbeddingAccessRepository $repository = new EmbeddingAccessRepository(),
	)
	{
	}

	public function set(string $moduleId, string $entityType, int $embeddingId, array $accessCodes): void
	{
		$this->setForEmbeddings($moduleId, $entityType, [$embeddingId => $accessCodes]);
	}

	public function setForEmbeddings(
		string $moduleId,
		string $entityType,
		array $accessCodesByEmbeddingId,
	): void
	{
		if (empty($accessCodesByEmbeddingId))
		{
			return;
		}

		$this->repository->replaceForEmbeddings(
			$moduleId,
			$entityType,
			EmbeddingAccessCodeNormalizer::normalizeMap($accessCodesByEmbeddingId),
		);
	}
}
