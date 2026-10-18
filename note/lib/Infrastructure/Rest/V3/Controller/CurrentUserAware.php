<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Rest\V3\Controller;

use Bitrix\Main\Engine\CurrentUser;

/**
 * Who the module writes rows on behalf of.
 *
 * The base controller declares its current user nullable, and it is filled by whoever builds the
 * controller - which the engine does on every real request and nobody else does at all. Casting that
 * null to an int gives 0, and a document created by "user 0" is not an error anywhere: the row saves,
 * the answer is 200, and the ownership is simply wrong. Asking the global user as a fallback is the
 * same answer the engine would have put there.
 */
trait CurrentUserAware
{
	protected function getCurrentUserId(): int
	{
		return (int)($this->getCurrentUser() ?? CurrentUser::get())->getId();
	}
}
