<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Exception\Category;

use Bitrix\Main\ArgumentException;

/**
 * A write asked for a category field the entity type does not accept: the field is read-only in the
 * category field model of that entity type, absent from it, or not part of the write contract at all.
 *
 * This is our own refusal, not one of the storage boundary - nothing has been written when it is
 * thrown, and it carries the offending field itself instead of going through
 * {@see StorageBoundaryExceptionMapper}. A calling scenario turns it into
 * {@see \Bitrix\Crm\V2\Public\Entity\Category\CategoryError::FIELD_NOT_WRITABLE} naming that field.
 *
 * @internal
 */
final class FieldNotWritableException extends ArgumentException
{
	public function __construct(string $fieldName)
	{
		parent::__construct("Category field '{$fieldName}' is not writable for this entity type.", $fieldName);
	}

	public function getFieldName(): string
	{
		return (string)$this->getParameter();
	}
}
