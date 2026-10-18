<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Integration\Landing;

use Bitrix\Landing\Site\Type;
use Bitrix\Main\Loader;

/**
 * Scoped helper for landing module's `\Bitrix\Landing\Site\Type::setScope()`.
 *
 * `SiteTable::getList()` and `LandingTable::getList()` quietly override any
 * `TYPE` / `SITE.TYPE` filter with the values produced by
 * `\Bitrix\Landing\Site\Type::getFilterType()`. Without setting the scope to
 * `KNOWLEDGE` first, our knowledge-base filters degrade to the default
 * `['PAGE','STORE','SMN','VIBE']` set and pull in unrelated landing pages.
 *
 * Use {@see runInKnowledgeScope} so the previous scope is restored even when
 * the callback throws.
 */
final class LandingScope
{
	public const KNOWLEDGE = Type::SCOPE_CODE_KNOWLEDGE;

	/**
	 * @template T
	 * @param callable():T $callback
	 * @return T
	 */
	public static function runInKnowledgeScope(callable $callback): mixed
	{
		if (!Loader::includeModule('landing'))
		{
			return $callback();
		}

		$previous = Type::getCurrentScopeId();
		Type::setScope(self::KNOWLEDGE);
		try
		{
			return $callback();
		}
		finally
		{
			// Restoring KNOWLEDGE means setting it again, not clearing it: nested calls are ordinary
			// here (a reader calls another one from inside its own callback), and clearing would leave
			// the outer call running without the scope it established.
			if ($previous === null)
			{
				Type::clearScope();
			}
			else
			{
				Type::setScope($previous);
			}
		}
	}
}
