<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\DataView\Dto;

final class SourceSchema
{
	/** @var SourceField[] */
	private readonly array $fields;

	/** @param SourceField[] $fields */
	public function __construct(array $fields)
	{
		$this->fields = array_values($fields);
	}

	/** @return SourceField[] */
	public function getFields(): array
	{
		return $this->fields;
	}

	public function getField(string $code): ?SourceField
	{
		foreach ($this->fields as $field)
		{
			if ($field->code === $code)
			{
				return $field;
			}
		}

		return null;
	}

	public function isEmpty(): bool
	{
		return $this->fields === [];
	}
}
