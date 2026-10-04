<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Item\FieldValueElement;

final readonly class FileUpload implements FieldValueElementInterface
{
	public function __construct(
		private string $fieldName,
		private FileUploadValue $fieldValue,
	)
	{
	}

	public function getFieldName(): string
	{
		return $this->fieldName;
	}

	public function getFieldValue(): FileUploadValue
	{
		return $this->fieldValue;
	}
}
