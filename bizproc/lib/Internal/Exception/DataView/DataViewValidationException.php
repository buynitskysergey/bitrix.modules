<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Exception\DataView;

use Bitrix\Bizproc\Internal\Exception\Exception;

/**
 * Carries the full list of DTO-01 validation errors accumulated by {@see DataViewValidator}
 * instead of failing on the first violation. Each entry is a normalized
 * {code, field, message} triple ready to be delivered as a controller error.
 */
class DataViewValidationException extends Exception
{
	/**
	 * @param array<int, array{code: string, field: string, message: string}> $errors
	 */
	public function __construct(private readonly array $errors)
	{
		parent::__construct(
			message: 'Data view definition is invalid.',
			code: self::CODE_DATA_VIEW_INVALID_DEFINITION,
		);
	}

	/**
	 * @return array<int, array{code: string, field: string, message: string}>
	 */
	public function getErrors(): array
	{
		return $this->errors;
	}
}
