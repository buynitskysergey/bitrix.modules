<?php

namespace Bitrix\UI\Format\BBCode;

use CBXSanitizer;
use CTextParser;

final class Converter
{
	private static ?CTextParser $parser = null;
	private static ?CBXSanitizer $sanitizer = null;
	private static ?string $plainTextTagPattern = null;
	private static ?string $plainTextBlockPattern = null;
	private static ?string $plainTextMediaPattern = null;
	private static ?string $plainTextForbiddenBlockPattern = null;

	public static function toHtml(string $bb): string
	{
		if ($bb === '')
		{
			return '';
		}

		$bb = Whitelist::stripForbiddenBbTags($bb);

		return self::sanitizeHtml(self::getParser()->convertText($bb));
	}

	public static function toPlainText(string $bb): string
	{
		if ($bb === '')
		{
			return '';
		}

		$bb = (string)preg_replace(self::getPlainTextForbiddenBlockPattern(), ' ', $bb);
		$bb = Whitelist::stripForbiddenBbTags($bb);

		$bb = (string)preg_replace(self::getPlainTextMediaPattern(), ' ', $bb);
		$bb = (string)preg_replace(self::getPlainTextBlockPattern(), ' ', $bb);
		$bb = (string)preg_replace(self::getPlainTextTagPattern(), '', $bb);
		$bb = str_replace(['&#91;', '&#93;'], ['[', ']'], $bb);
		$bb = (string)preg_replace('/[ \\t\\x{00A0}]+/u', ' ', $bb);

		return trim($bb);
	}

	/**
	 * Block markup is replaced with a space, otherwise neighbour items glue together.
	 */
	private static function getPlainTextBlockPattern(): string
	{
		if (self::$plainTextBlockPattern === null)
		{
			self::$plainTextBlockPattern = '#' . Whitelist::getTagPattern(['list', 'p']) . '|\\[\\*\\]#iu';
		}

		return self::$plainTextBlockPattern;
	}

	/**
	 * A media block is dropped together with its payload: search and public text must not index
	 * an embed url. Only real markup counts as the opening tag, so a text like `[img of the report]`
	 * is not an opening tag and does not swallow the words after it.
	 */
	private static function getPlainTextMediaPattern(): string
	{
		if (self::$plainTextMediaPattern === null)
		{
			self::$plainTextMediaPattern = '#' . Whitelist::getPairedTagPattern(Whitelist::getMediaTags()) . '#isu';
		}

		return self::$plainTextMediaPattern;
	}

	/**
	 * Forbidden block markup gets a space for the same reason `[p]` does: without it neighbour blocks
	 * glue into one word. It runs before the forbidden tags are dropped, which happens without a space
	 * and keeps an inline `[color]` from cutting its word in two.
	 */
	private static function getPlainTextForbiddenBlockPattern(): string
	{
		if (self::$plainTextForbiddenBlockPattern === null)
		{
			self::$plainTextForbiddenBlockPattern =
				'#' . Whitelist::getTagPattern(Whitelist::getForbiddenBlockTags()) . '#iu'
			;
		}

		return self::$plainTextForbiddenBlockPattern;
	}

	/**
	 * Matches whitelisted tags only: any other bracketed token is plain user text and must survive.
	 * The `[*]` list marker is handled apart, together with the list markup itself.
	 */
	private static function getPlainTextTagPattern(): string
	{
		if (self::$plainTextTagPattern === null)
		{
			self::$plainTextTagPattern = '#' . Whitelist::getTagPattern(Whitelist::getTags()) . '#iu';
		}

		return self::$plainTextTagPattern;
	}

	private static function sanitizeHtml(string $html): string
	{
		if ($html === '' || !str_contains($html, '<'))
		{
			return $html;
		}

		return self::getSanitizer()->SanitizeHtml($html);
	}

	private static function getParser(): CTextParser
	{
		if (self::$parser === null)
		{
			$parser = new CTextParser();
			$parser->allow = Whitelist::getParserAllow();

			self::$parser = $parser;
		}

		return self::$parser;
	}

	private static function getSanitizer(): CBXSanitizer
	{
		if (self::$sanitizer === null)
		{
			$sanitizer = new CBXSanitizer();
			$sanitizer->ApplyDoubleEncode(false);
			$sanitizer->DelAllTags();
			$sanitizer->AddTags(Whitelist::getSanitizerTags());

			self::$sanitizer = $sanitizer;
		}

		return self::$sanitizer;
	}
}
