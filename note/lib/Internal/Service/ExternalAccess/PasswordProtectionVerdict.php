<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\ExternalAccess;

/**
 * What the brute-force protection says about one attempt to name a password.
 *
 * The captcha code is generated only when the captcha is actually required: a code handed out in
 * advance would be a code the visitor never asked for, and the client would have to guess whether
 * to show the picture. The demand never travels without its code: a check that cannot be shown
 * cannot be solved either.
 */
final class PasswordProtectionVerdict
{
	private function __construct(
		public readonly bool $captchaRequired,
		public readonly ?string $captchaCode,
	) {}

	public static function allowed(): self
	{
		return new self(false, null);
	}

	public static function captchaRequired(string $captchaCode): self
	{
		return new self(true, $captchaCode);
	}
}
