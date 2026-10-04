<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\CallAssessment\Summary\Formatter;

/**
 * Neutralizes BBCode control characters in user-provided call subjects before
 * they are embedded into IM messages, which are parsed as BBCode by
 * \Bitrix\Im\Text. Without this a call theme (CALL_SUMMARY.THEME) containing
 * sequences like "[/URL]" or "[B]" could break out of the surrounding
 * [URL=...]...[/URL] tag and inject arbitrary markup or links.
 */
final class SubjectSanitizer
{
	public static function sanitize(string $subject): string
	{
		return str_replace(['[', ']'], '', $subject);
	}
}
