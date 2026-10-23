<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\BizprocDesigner;

use Bitrix\BizprocDesigner\Public\Document\Interface\DocumentFieldsContributor as Contributor;
use Bitrix\Crm\Integration\BizProc\Document\Item;

/**
 * Parent entity fields of the classic crm documents: unlike the documents built on a factory, their
 * base field set does not contain PARENT_ID_*.
 */
class DocumentFieldsContributor implements Contributor
{
	private const CLASSIC_DOCUMENT_CLASSES = [
		\CCrmDocumentLead::class,
		\CCrmDocumentDeal::class,
		\CCrmDocumentCompany::class,
		\CCrmDocumentContact::class,
	];

	public function getDocumentFields(array $documentType): array
	{
		$documentClass = (string)($documentType[1] ?? '');
		if (!in_array($documentClass, self::CLASSIC_DOCUMENT_CLASSES, true))
		{
			return [];
		}

		return Item::getParentFields(\CCrmOwnerType::ResolveID((string)($documentType[2] ?? '')));
	}
}
