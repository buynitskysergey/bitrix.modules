<?php

declare(strict_types=1);

namespace Bitrix\Note\Integration\BizProc\NodeFilter;

use Bitrix\Note\Internal\Integration\Bizproc\NodeFilter\TargetDocumentResolver as InternalResolver;

/**
 * The address bizproc releases older than 26.2000.0 look the resolver up by: they know only the
 * `Bitrix\<Module>\Integration\BizProc` convention, not `Internal`. Without it the selection of the knowledge
 * base node is lost there, and an action with a selection silently works on the document of the process.
 * Newer releases try this address first as well, so both get the same answers.
 */
final class TargetDocumentResolver implements \Bitrix\Bizproc\Public\Activity\Interface\TargetDocumentResolver
{
	private readonly InternalResolver $resolver;

	public function __construct()
	{
		$this->resolver = new InternalResolver();
	}

	public function supports(\CBPActivity $activity): bool
	{
		return $this->resolver->supports($activity);
	}

	public function resolveDocumentId(\CBPActivity $activity): array
	{
		return $this->resolver->resolveDocumentId($activity);
	}

	public function resolveDocumentType(array $documentId, array $fallbackDocumentType): array
	{
		return $this->resolver->resolveDocumentType($documentId, $fallbackDocumentType);
	}
}
