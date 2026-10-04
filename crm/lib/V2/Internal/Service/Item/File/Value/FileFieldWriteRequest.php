<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Item\File\Value;

final class FileFieldWriteRequest
{
	/**
	 * @param null|FileUploadInput|array<int, int|FileUploadInput> $value
	 */
	public function __construct(
		public readonly string $fieldName,
		public readonly bool $multiple,
		public readonly mixed $value,
	)
	{
	}
}
