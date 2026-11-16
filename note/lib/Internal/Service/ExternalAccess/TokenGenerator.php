<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\ExternalAccess;

use Bitrix\Main\DB\SqlQueryException;
use Bitrix\Main\Security\Random;
use Bitrix\Main\SystemException;

/**
 * Issues the token of a public link.
 *
 * The token is the whole secret of the feature, so it comes from the random source of the core and
 * is derived from nothing: neither the knowledge base, nor the moment, nor the author. Uniqueness
 * is decided by the unique index while the row is written, not by a preliminary read: a read would
 * leave a window between the check and the insert, and a collision would silently overwrite a link
 * that has already been handed out.
 */
final class TokenGenerator
{
	public const TOKEN_LENGTH = 32;
	private const ALPHABET = Random::ALPHABET_NUM | Random::ALPHABET_ALPHALOWER | Random::ALPHABET_ALPHAUPPER;
	private const MAX_ATTEMPTS = 5;
	private const TOKEN_UNIQUE_INDEX = 'ux_note_external_access_token';

	public function generate(): string
	{
		return Random::getStringByAlphabet(self::TOKEN_LENGTH, self::ALPHABET);
	}

	/**
	 * Issues a token and hands it to the writer, which stores it under the unique index. On a
	 * collision the pair is repeated with a new token; after the attempt budget the write fails
	 * loudly instead of reusing a token that is already out there.
	 *
	 * Only a violation of the token index counts as a collision. Any other write error - the unique
	 * index of the knowledge base above all - means the row cannot be written at all, and repeating
	 * it with a new token would only produce the same error five times over.
	 *
	 * @param callable(string): mixed $writer
	 * @return mixed whatever the writer returns
	 * @throws SqlQueryException any write error that is not a token collision
	 * @throws SystemException
	 */
	public function issueUnique(callable $writer): mixed
	{
		$attempt = 0;

		while (true)
		{
			$attempt++;

			try
			{
				return $writer($this->generate());
			}
			catch (SqlQueryException $e)
			{
				if (!$this->isTokenCollision($e))
				{
					throw $e;
				}

				if ($attempt >= self::MAX_ATTEMPTS)
				{
					throw new SystemException(
						'Note external access: unique token was not issued in ' . self::MAX_ATTEMPTS . ' attempts',
						0,
						__FILE__,
						__LINE__,
						$e,
					);
				}
			}
		}
	}

	/**
	 * Whether the database refused the write because this very token is already stored. The name of
	 * the index is what tells it apart: the message of a duplicate entry carries the index that was
	 * violated, and the token has one of its own.
	 */
	private function isTokenCollision(SqlQueryException $e): bool
	{
		return str_contains($e->getDatabaseMessage(), self::TOKEN_UNIQUE_INDEX);
	}
}
