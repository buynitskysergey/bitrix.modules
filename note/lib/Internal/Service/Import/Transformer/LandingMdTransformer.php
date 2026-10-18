<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Import\Transformer;

use Bitrix\Note\Internal\Service\Import\ImportLogger;
use Bitrix\Note\Internal\Service\Import\Source\LandingSource;

/**
 * Post-processor for landing-rendered markdown.
 *
 * LandingSource already runs the block->markdown pipeline (BlockConverter / HtmlToMarkdown)
 * before handing text to FillContentStep. This transformer:
 *
 *  - defines the regex {@see getPattern()} that surfaces `landing-file://<id>` placeholders
 *    so DownloadAttachmentsStep can resolve them;
 *  - caps oversized markdown at 10 MB (ALG-02) to stay below MySQL mediumtext limits;
 *  - unwraps the placeholders the attachment chain failed to resolve
 *    ({@see unwrapUnresolvedAttachments()}), so neither internal scheme reaches a reader.
 */
class LandingMdTransformer extends ImportMdTransformer
{
	private const MAX_MARKDOWN_BYTES = 10 * 1024 * 1024;

	protected function getPattern(): string
	{
		$file = preg_quote(LandingSource::ATTACHMENT_SCHEME, '/');          // landing-file://
		$ext = preg_quote(LandingSource::EXTERNAL_ATTACHMENT_SCHEME, '/');  // landing-ext://

		// Two internal placeholder schemes produced by the block converters, both optionally
		// carrying a `?w=..&a=..` positioning suffix (width/align):
		//   landing-file://<coreFileId>  - a file already stored on this portal (copied locally)
		//   landing-ext://<base64url>    - an external image URL to be downloaded over HTTP
		// base64url ([A-Za-z0-9-_]) keeps the payload free of the `?`/`&`/`/` that would collide
		// with the suffix, so the URL and its positioning survive as separate groups.
		// The trailing look-ahead refuses one thing only: a placeholder already carrying the attribute
		// block of a resolved asset (`{..fileId=N..}` - written by older imports and, for a table cell,
		// by enrichTableRowAssets()), which would otherwise be downloaded a second time on every
		// stepper pass. Any other brace right after the link - the `{color:..}`/`{/color}` token of
		// HtmlToMarkdown, or a page's own words - leaves the attachment findable.
		return '/(?P<linkPart>!?\[[^\]]*\])\((?P<url>(?:'
			. '(?:' . $file . '(?P<attachmentId>\d+))'
			. '|(?:' . $ext . '(?P<ext>[A-Za-z0-9\-_]+))'
			. ')(?:\?(?P<attrs>[^)\n]*))?)\)(?!\{[^{}\n]*fileId\s*=)/';
	}

	/**
	 * landing-file matches expose the numeric core file id; landing-ext matches expose the
	 * base64url token, which we hand back scheme-prefixed so {@see LandingSource::downloadAttachment()}
	 * can tell an external URL from a local file id by its prefix.
	 */
	protected function attachmentKey(array $matches): string
	{
		$fileId = (string)($matches['attachmentId'] ?? '');
		if ($fileId !== '')
		{
			return $fileId;
		}

		$ext = (string)($matches['ext'] ?? '');

		return $ext !== '' ? LandingSource::EXTERNAL_ATTACHMENT_SCHEME . $ext : '';
	}

	/**
	 * Turns every placeholder still left in the text into something the editor can show: an external
	 * image goes back to the address it had before the placeholder was wrapped around it, so a failed
	 * download degrades to a remote picture instead of an empty one, and a file that could not be
	 * copied from landing leaves only its label behind. Neither internal scheme may reach a reader.
	 */
	public function unwrapUnresolvedAttachments(string $markdown): string
	{
		if ($markdown === '')
		{
			return $markdown;
		}

		return preg_replace_callback(
			$this->getPattern(),
			static function (array $matches): string {
				$token = (string)($matches['ext'] ?? '');
				$url = $token === '' ? null : LandingSource::decodeExternalAttachmentUrl($token);

				return $url === null
					? self::linkText($matches['linkPart'])
					: $matches['linkPart'] . '(' . self::markdownSafeUrl($url) . ')'
				;
			},
			$markdown,
		) ?? $markdown;
	}

	/**
	 * The visible part of a link - `![alt]` / `[label]` reduced to the text between the brackets.
	 * That is all an attachment with no address to fall back to may leave in the document; an
	 * unlabelled one disappears entirely.
	 */
	private static function linkText(string $linkPart): string
	{
		return trim(substr($linkPart, (int)strpos($linkPart, '[') + 1, -1));
	}

	/**
	 * Percent-encodes what would end a markdown link destination early - whitespace and round
	 * brackets. These are their canonical escapes, so the address keeps working.
	 */
	private static function markdownSafeUrl(string $url): string
	{
		$url = (string)preg_replace('/\s/', '%20', $url);

		return str_replace(['(', ')'], ['%28', '%29'], $url);
	}

	public function preprocessMarkdown(string $markdown): string
	{
		if ($markdown === '')
		{
			return $markdown;
		}

		if (strlen($markdown) > self::MAX_MARKDOWN_BYTES)
		{
			ImportLogger::logError(sprintf(
				'LandingMdTransformer: markdown truncated from %d to %d bytes',
				strlen($markdown),
				self::MAX_MARKDOWN_BYTES,
			));
			$markdown = mb_strcut($markdown, 0, self::MAX_MARKDOWN_BYTES);
		}

		return $markdown;
	}
}
