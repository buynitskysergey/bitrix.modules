<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Integration\Bizproc\NodeFilter;

use Bitrix\Bizproc\Public\Activity\Interface\FixedDocumentComplexActivity;
use Bitrix\Note\Internal\Integration\Bizproc\Document\NoteDocument;

final class FilterResultPropertyResolver
	implements \Bitrix\Bizproc\Public\Activity\Interface\FilterResultPropertyResolver
{
	public function supports(\CBPActivity $activity): bool
	{
		$documentType = $activity instanceof FixedDocumentComplexActivity
			? $activity::getDocumentTypeForNodeAction()
			: $activity->getDocumentType()
		;

		return ($documentType[0] ?? null) === NoteDocument::MODULE_ID;
	}

	public function resolveProperties(\CBPActivity $activity): array
	{
		return Resolver::resolveDocuments($activity);
	}
}
