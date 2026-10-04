<?php

namespace Bitrix\Rest\V3;

/**
 * Marks a REST server implementation as running in deferred (worker) mode.
 *
 * When the server implements this interface and isDeferredMode() returns true,
 * OrmRepository will not apply the default pagination limit of 50 records.
 */
interface DeferredModeAwareInterface
{
	public function isDeferredMode(): bool;
}

