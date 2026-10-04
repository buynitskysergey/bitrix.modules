<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Exception\DataView;

use Bitrix\Bizproc\Internal\Exception\Exception;

class InvalidDataViewDefinitionException extends Exception
{
	public const VIOLATION_DEFINITION = 'definition';
	public const VIOLATION_PERIOD = 'period';
	public const VIOLATION_AGGREGATE_FN = 'aggregate_fn';
	public const VIOLATION_COLUMN_DUPLICATE = 'column_duplicate';
	public const VIOLATION_FIELD_UNKNOWN = 'field_unknown';
	public const VIOLATION_STAMP_INVALID = 'stamp_invalid';
	public const VIOLATION_TEMPLATE_SOURCE_FOREIGN = 'template_source_foreign';
	public const VIOLATION_FORMULA_INVALID = 'formula_invalid';
	public const VIOLATION_FORMULA_REFERENCE = 'formula_reference';
	public const VIOLATION_FORMULA_FUNCTION = 'formula_function';
	public const VIOLATION_COLUMN_TYPE_CHANGED = 'column_type_changed';

	public function __construct(
		$message = '',
		private readonly ?string $field = null,
		private readonly ?string $violation = null,
	) {
		$message = $message === '' ? 'Invalid data view definition' : $message;

		parent::__construct(
			message: $message,
			code: self::CODE_DATA_VIEW_INVALID_DEFINITION,
		);
	}

	public static function forField(string $field, string $message, ?string $violation = null): self
	{
		return new self(sprintf('%s (field: "%s")', $message, $field), $field, $violation);
	}

	public function getField(): ?string
	{
		return $this->field;
	}

	public function getViolation(): ?string
	{
		return $this->violation;
	}
}
