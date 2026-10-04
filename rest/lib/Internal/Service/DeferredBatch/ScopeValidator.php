<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Service\DeferredBatch;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Rest\V3\Interaction\Request\BatchRequest;
use Bitrix\Rest\V3\Schema\SchemaManager;
use Bitrix\Rest\V3\Schema\Scope;
use CRestUtil;

/**
 * Validates that the caller has the required scopes for every method in a batch request.
 *
 * Follows ALG-SCOPE-CHECK from SDD-backend-deferred-batch-2026-05-26.md.
 */
class ScopeValidator
{
	private SchemaManager $schemaManager;

	public function __construct()
	{
		$this->schemaManager = ServiceLocator::getInstance()->get(SchemaManager::class);
	}

	public function validate(BatchRequest $batch, array $availableScopes): Result
	{
		$result = new Result();

		foreach ($batch->getItems() as $item)
		{
			$method = $item->getMethod();

			// The 'batch' method itself does not require scope validation.
			if ($method === 'batch')
			{
				continue;
			}

			$methodDescription = $this->schemaManager->getMethodDescription($method);
			if ($methodDescription === null)
			{
				$result->addError(new Error(
					"Method '{$method}' not found",
					'INVALID_METHOD',
				));

				return $result;
			}

			/** @var string $methodScope */
			foreach ($methodDescription->scopes as $methodScope)
			{
				/** @var Scope $availableScope */
				foreach ($availableScopes as $availableScope)
				{
					if ($availableScope->path === $methodScope)
					{
						continue 3;
					}
				}
			}

			$errorMessage = "Insufficient scope for method '{$method}': required one of [" . implode(', ', $methodDescription->scopes) . "]";

			$result->addError(new Error(
				$errorMessage,
				'INSUFFICIENT_SCOPE',
			));

			return $result;
		}

		return $result;
	}
}

