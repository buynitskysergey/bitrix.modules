<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Integration\Bizproc\NodeFilter;

use Bitrix\Bizproc\Public\Activity\Interface\FixedDocumentComplexActivity;
use Bitrix\Note\Internal\Integration\Bizproc\Document\NoteDocument;

final class TargetDocumentResolver implements \Bitrix\Bizproc\Public\Activity\Interface\TargetDocumentResolver
{
	public function supports(\CBPActivity $activity): bool
	{
		return (self::resolveExpectedDocumentType($activity)[0] ?? null) === NoteDocument::MODULE_ID;
	}

	public function resolveDocumentId(\CBPActivity $activity): array
	{
		$documentId = Resolver::resolveDocumentId($activity);
		if (is_array($documentId))
		{
			return $documentId;
		}

		return Resolver::hasTargetFilterId($activity) ? [] : self::resolveFallbackDocumentId($activity);
	}

	public function resolveDocumentType(array $documentId, array $fallbackDocumentType): array
	{
		return ($documentId[0] ?? null) === NoteDocument::MODULE_ID
			? NoteDocument::complexDocumentType()
			: $fallbackDocumentType
		;
	}

	private static function resolveExpectedDocumentType(\CBPActivity $activity): array
	{
		$parent = $activity->parent ?? null;
		if ($parent instanceof FixedDocumentComplexActivity)
		{
			return $parent::getDocumentTypeForNodeAction();
		}

		return $activity->getDocumentType();
	}

	private static function resolveFallbackDocumentId(\CBPActivity $activity): array
	{
		$documentId = $activity->getDocumentId();
		if (is_array($documentId))
		{
			return $documentId;
		}

		$rootDocumentId = $activity->getRootActivity()->getDocumentId();

		return is_array($rootDocumentId) ? $rootDocumentId : [];
	}
}
