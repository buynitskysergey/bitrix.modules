<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Exceptions;

/**
 * Marks the domain exceptions that mean "the requested object is not available to the caller":
 * it does not exist, it is archived/trashed, or access to it is denied. It carries no data — the
 * distinction between the concrete reasons stays internal to the module.
 *
 * Exists so that consumers outside note (currently the rag source-sync connector, which treats an
 * unavailable object as "nothing to index" while a transient failure must NOT be swallowed) can
 * catch the contract instead of the concrete Internal classes. The classes stay where note owns
 * them and may be renamed or moved without breaking the consumers.
 */
interface ObjectUnavailableInterface
{
}
