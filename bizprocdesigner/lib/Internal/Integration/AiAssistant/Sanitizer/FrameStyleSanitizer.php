<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Sanitizer;

/**
 * Server-side sanitiser for the BBCode styling a frame overlay carries from the external REST agent.
 *
 * The frame title is intentionally NOT handled here: like every other agent title it is stored raw
 * (validated for type/length only) and HTML-escaped exactly once at the render boundary by the frontend
 * (Vue interpolation / Text.encode). A server-side encode would double-escape it - "R&D" would reach the
 * user as "R&amp;D" - and break round-trip idempotency (template.get would return an already-encoded title
 * that a verbatim re-send would encode again).
 *
 * frameContent is BBCode, rendered on the canvas through ui.bbcode.formatter.html-formatter into a safe
 * DOM via createTextNode, so it must NOT be HTML-encoded: encoding "5 < 10" would double-escape it into
 * "5 &lt; 10". Instead it is tag-whitelisted: only the editor's own BBCode tags survive, any other
 * "[tag]" is neutralised into inert text, and dangerous URL schemes inside url/img tags - both in the
 * =attribute and in the tag body - are defused.
 *
 * The sanitiser is defense-in-depth: the canvas renderer is already safe, but frameContent is persisted in
 * the stored template and reaches other consumers, so it is cleaned at the point of acceptance.
 */
final class FrameStyleSanitizer
{
	/**
	 * BBCode tags the text editor emits (bold/italic/underline/strikethrough, lists, link, quote, code,
	 * image). Mirrors the editor toolbar in
	 * install/js/bizprocdesigner/editor/chart/src/shared/ui/text-editor/services/text-editor-service.js.
	 */
	private const ALLOWED_BBCODE = ['b', 'i', 'u', 's', 'list', '*', 'url', 'quote', 'code', 'img'];

	/** Hard length bound for frame BBCode content; generous enough to never clip legitimate text. */
	public const MAX_CONTENT_LENGTH = 20000;

	public static function sanitizeContent(string $content): string
	{
		// Hard-bound the input up front (cheap DoS guard) on a safe boundary, so an over-length direct caller
		// never leaves a half tag for the tag pass below to mis-read.
		$content = self::truncateToSafeBoundary($content, self::MAX_CONTENT_LENGTH);

		// The optional "=attribute" tolerates whitespace before "=" ("[url =javascript:...]"), so such a tag
		// is still recognised and its scheme defused instead of being left as raw text.
		$content = (string)preg_replace_callback(
			'/\[(\/?)([a-zA-Z*][a-zA-Z0-9]*)(\s*=[^\[\]]*)?\]/',
			static function (array $match): string {
				$tagName = mb_strtolower($match[2]);
				if (!in_array($tagName, self::ALLOWED_BBCODE, true))
				{
					// Disallowed tag: escape the opening bracket so it is no longer a BBCode tag and
					// renders as inert text, without touching the text around it.
					return '&#91;' . $match[1] . $match[2] . ($match[3] ?? '') . ']';
				}

				$attribute = $match[3] ?? '';
				if (($tagName === 'url' || $tagName === 'img') && $attribute !== '')
				{
					$attribute = self::neutralizeDangerousScheme($attribute);
				}

				return '[' . $match[1] . $match[2] . $attribute . ']';
			},
			$content,
		);

		// Defuse dangerous schemes carried in the body of a url/img tag ("[img]javascript:...[/img]"), which
		// the per-tag pass above only reaches through the =attribute. The closing tag is optional ("|$"): an
		// unclosed "[url]javascript:..." must be defused too, not left intact just because "[/url]" is missing.
		$content = (string)preg_replace_callback(
			'/(\[(?:url|img)(?:\s*=[^\[\]]*)?\])(.*?)(\[\/(?:url|img)\]|$)/is',
			static function (array $match): string {
				return $match[1] . self::neutralizeDangerousScheme($match[2]) . $match[3];
			},
			$content,
		);

		// Neutralising a disallowed "[tag]" into "&#91;tag]" GROWS the string (+4 chars), so the input length
		// check upstream is not enough to keep the stored value within MAX_CONTENT_LENGTH. Re-bound the final
		// result, cutting only on a safe boundary so a "&#...;" entity or a "[...]" tag is never split.
		return self::truncateToSafeBoundary($content, self::MAX_CONTENT_LENGTH);
	}

	/**
	 * Truncates to at most $maxLength characters without leaving broken markup at the tail: it never cuts in
	 * the middle of a "[...]" bbcode tag (drops a trailing unmatched "[") nor in the middle of a "&...;" HTML
	 * entity such as the "&#91;" a neutralised tag emits (drops a trailing unterminated "&"). Both trims only
	 * remove characters, so the result never exceeds $maxLength.
	 */
	private static function truncateToSafeBoundary(string $content, int $maxLength): string
	{
		if (mb_strlen($content) <= $maxLength)
		{
			return $content;
		}

		$content = mb_substr($content, 0, $maxLength);

		// Never end inside a "[...]" tag: if an opening bracket is left unmatched by a later "]", drop it.
		$lastOpen = mb_strrpos($content, '[');
		$lastClose = mb_strrpos($content, ']');
		if ($lastOpen !== false && ($lastClose === false || $lastOpen > $lastClose))
		{
			$content = mb_substr($content, 0, $lastOpen);
		}

		// Never end inside a "&...;" entity: if an "&" is left unterminated by a later ";", drop it.
		$lastAmp = mb_strrpos($content, '&');
		$lastSemicolon = mb_strrpos($content, ';');
		if ($lastAmp !== false && ($lastSemicolon === false || $lastAmp > $lastSemicolon))
		{
			$content = mb_substr($content, 0, $lastAmp);
		}

		return $content;
	}

	/**
	 * Defuses javascript:/vbscript:/data: URL schemes inside a url/img BBCode tag - both in its =attribute
	 * and in its body - by dropping the colon, so "javascript:alert(1)" can no longer materialise into an
	 * executable href. Idempotent: a defused "javascript_..." has no colon left for a second pass to match.
	 *
	 * Control characters and insignificant whitespace ("java\tscript:", "ja\nvascript:") are stripped first:
	 * the browser ignores them when it resolves an href, so the scheme must be matched as if they were absent.
	 * Mirrors the frontend ui.bbcode sanitizeUrl, which drops the same [\x00-\x20] run from a URL before it
	 * decides the scheme is safe.
	 */
	private static function neutralizeDangerousScheme(string $value): string
	{
		$value = (string)preg_replace('/[\x00-\x20]/', '', $value);

		return (string)preg_replace('/(javascript|vbscript|data)\s*:/i', '$1_', $value);
	}
}
