<?php

declare(strict_types=1);

namespace Bitrix\Main\DB\Ddl\Column;

final class ColumnStateData
{
	private readonly string $type;
	private readonly ColumnAutoincrementType $autoincrementType;
	private readonly ?string $sequenceSchema;
	private readonly ?string $sequenceName;
	private readonly ?string $sequenceType;

	public function __construct(
		string $type,
		ColumnAutoincrementType $autoincrementType,
		?string $sequenceSchema = null,
		?string $sequenceName = null,
		?string $sequenceType = null,
	)
	{
		$this->type = strtolower($type);
		$this->autoincrementType = $autoincrementType;
		$this->sequenceSchema = $sequenceSchema;
		$this->sequenceName = $sequenceName;
		$this->sequenceType = $sequenceType === null ? null : strtolower($sequenceType);
	}

	public function getType(): string
	{
		return $this->type;
	}

	public function getAutoincrementType(): ColumnAutoincrementType
	{
		return $this->autoincrementType;
	}

	public function getSequenceSchema(): ?string
	{
		return $this->sequenceSchema;
	}

	public function getSequenceName(): ?string
	{
		return $this->sequenceName;
	}

	public function getSequenceType(): ?string
	{
		return $this->sequenceType;
	}
}
