<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Exceptions;

/**
 * Thrown when an independent destructive operation (archive / delete / move / re-parent)
 * targets a collection's main document. The main document may only be edited in place
 * or removed via the owning collection's cascade delete.
 */
final class MainDocumentOperationException extends \RuntimeException
{
}
