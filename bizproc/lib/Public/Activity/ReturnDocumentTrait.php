<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Activity;

use Bitrix\Bizproc\FieldType;
use Bitrix\Main\Localization\Loc;

trait ReturnDocumentTrait
{
	public function execute(): int
	{
		$document = $this->getEventData()['Document'] ?? null;

		$this->setProperties([static::getReturnDocumentFieldName() => $document]);
		$this->setPropertiesTypes(static::buildReturnDocumentProperties(is_array($document) ? $document : null));

		return \CBPActivityExecutionStatus::Closed;
	}

	protected static function getReturnDocumentFieldName(): string
	{
		return 'ReturnDocument';
	}

	protected static function buildDocumentPropertyMap(array $moduleIds = []): array
	{
		$map = [
			'Name' => static::getDefaultReturnDocumentTitle(),
			'FieldName' => 'Document',
			'Type' => FieldType::DOCUMENT_TYPE,
			'Multiple' => false,
			'Required' => true,
			'AllowSelection' => false,
		];

		if ($moduleIds)
		{
			$map['Settings'] = ['entity' => ['options' => ['moduleIds' => $moduleIds]]];
		}

		return $map;
	}

	protected static function validateDocumentProperty(array $properties): array
	{
		if (\CBPHelper::isEmptyValue($properties['Document'] ?? null))
		{
			return [[
				'code' => 'Document',
				'message' => (string)Loc::getMessage(
					'BIZPROC_PUBLIC_ACTIVITY_DOCUMENT_REQUIRED',
					['#DOCUMENT#' => static::getDefaultReturnDocumentTitle()],
				),
			]];
		}

		return [];
	}

	protected static function buildReturnDocumentProperties(?array $document = null): array
	{
		return [
			static::getReturnDocumentFieldName() => static::buildReturnDocumentMapType($document),
		];
	}

	protected static function buildReturnDocumentMapType(?array $document = null): array
	{
		return [
			'Name' => static::getReturnDocumentTitle($document),
			'Type' => FieldType::DOCUMENT,
			'Default' => $document,
		];
	}

	protected static function getReturnDocumentTitle(?array $document = null): string
	{
		return $document
			? static::getDocumentName($document)
			: static::getDefaultReturnDocumentTitle()
		;
	}

	protected static function getDefaultReturnDocumentTitle(): string
	{
		return Loc::getMessage('BIZPROC_PUBLIC_ACTIVITY_RETURN_DOCUMENT_TITLE') ?? '';
	}

	protected static function getDocumentName(array $documentType): string
	{
		$name = \CBPRuntime::getRuntime()->getDocumentService()->getDocumentTypeName($documentType);

		return \CBPHelper::stringify($name);
	}
}
