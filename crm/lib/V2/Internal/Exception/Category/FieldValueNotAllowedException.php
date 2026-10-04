<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Exception\Category;

use Bitrix\Main\ArgumentException;

/**
 * A write gave a field of a category or of a stage a value the domain does not accept - a name of
 * nothing but blanks above all, which the storage boundary drops from the write instead of refusing
 * ({@see \CCrmStatus::Update()}, {@see \Bitrix\Crm\Category\DealCategory::update()}) and thereby
 * answers a request that changed nothing with success.
 *
 * This is our own refusal, not one of the storage boundary - nothing has been written when it is
 * thrown, and it carries the offending field itself instead of going through
 * {@see StorageBoundaryExceptionMapper}. A calling scenario turns it into
 * {@see \Bitrix\Crm\V2\Public\Entity\Category\CategoryError::FIELD_VALUE_NOT_ALLOWED} naming that
 * field.
 *
 * @internal
 */
final class FieldValueNotAllowedException extends ArgumentException
{
	public function __construct(string $fieldName)
	{
		parent::__construct("Category field '{$fieldName}' was given a value the domain does not accept.", $fieldName);
	}

	public function getFieldName(): string
	{
		return (string)$this->getParameter();
	}
}
