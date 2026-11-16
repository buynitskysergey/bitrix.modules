<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\ExternalAccess;

/**
 * The outcome of one comparison of the password of a public link, carried from the service that
 * compares to the service that writes the confirmation mark.
 *
 * It exists to carry the version of the secret the comparison actually passed against. A mark made
 * for "the password of this link, whatever it is right now" is a mark made for a password the
 * visitor may never have named: the moderator can replace it between the comparison and the mark,
 * and a re-read there would hand a visitor who knew the old password a confirmation of the new one.
 * So the hash travels with the verdict and the mark is bound to it.
 *
 * The hash goes no further than the two services that need it: the confirmation controller holds
 * this object and has no method to read the secret out of it.
 */
final class PasswordCheckResult
{
	private function __construct(
		public readonly bool $isAllowed,
		private readonly ?string $verifiedHash,
	) {}

	/** The named password matched the hash stored at the moment of the comparison. */
	public static function matched(string $storedHash): self
	{
		return new self(true, $storedHash);
	}

	/**
	 * The link carries no password at all - dropped while the visitor was standing at the prompt, or
	 * never set. There is nothing to name, so there is nothing to refuse and nothing to bind a mark
	 * to.
	 */
	public static function unprotected(): self
	{
		return new self(true, null);
	}

	/** A wrong password, and a link with no record behind it: told apart nowhere above this class. */
	public static function refused(): self
	{
		return new self(false, null);
	}

	/**
	 * @return string|null the exact version of the secret the comparison passed against, null when
	 *                     the link has no password
	 */
	public function getVerifiedHash(): ?string
	{
		return $this->verifiedHash;
	}
}
