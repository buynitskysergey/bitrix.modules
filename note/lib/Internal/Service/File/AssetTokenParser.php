<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\File;

use Bitrix\Note\Internal\Util\IdNormalizer;

/**
 * Extracts fileId references from asset tokens (`[[image|file|video fileId=N ...]]`) inside a
 * markdown body. Used by {@see FileReachabilityService} to decide whether a file is still
 * referenced by the current document content or by any surviving version snapshot.
 *
 * The regex is intentionally MORE PERMISSIVE than the frontend's canonical token grammar
 * (see note-asset-parser.js), because this parser also scans immutable snapshots that may have
 * been written by an earlier/looser version of the frontend parser. The failure modes are
 * asymmetric: a missed fileId marks a still-referenced file unreachable, which gets it
 * physically deleted (data loss); a spurious extra fileId only keeps a file alive longer
 * (safe). So this parser errs toward extracting `fileId=N` wherever it appears inside any
 * `[[...]]` token, without validating the asset type keyword, attribute whitelist, or spacing.
 *
 * The legacy enriched form `![label](url){fileId=N documentId=M type="image" ...}` — written by
 * the importer before it switched to compact tokens, and still readable by the editor — counts
 * as a reference too: its attribute braces are scanned by the same rule. Documents never
 * re-saved since then keep their attachments only in that shape.
 *
 * Disk object ids are read by a rule of their own, and a strict one — see
 * {@see extractDiskObjectIds()}: for a file that belongs to Disk the two failure modes trade places.
 */
final class AssetTokenParser
{
	private const TOKEN_PATTERN = '/\[\[([^\[\]]*)\]\]/u';
	private const ENRICHED_ATTRS_PATTERN = '/\{([^{}\n]*)\}/u';
	private const FILE_ID_PATTERN = '/fileId\s*=\s*"?(\d+)/u';

	/**
	 * A whole Disk token in the block form the editor requires, not just an id somewhere inside square
	 * brackets: the token opens the line's content, closes with `]]` and ends the line. This mirrors
	 * NOTE_DISK_ASSET_RE (note-asset-parser.js) deliberately closely, because a match here ATTACHES a
	 * Disk file to the document, and an attachment is what opens that file to everyone who may read the
	 * document — so a token the editor does not display as an attachment (mid-sentence, unterminated,
	 * quoted in prose) must count for nothing.
	 *
	 * Block structure may precede the token on its line — indentation, blockquote markers, list markers:
	 * the editor tokenizes an asset nested in a list item exactly like a top-level one, so a nested
	 * attachment has to be seen here too. The prefix is matched atomically: every character it may
	 * consume is block punctuation, `[[` never is, so the longest prefix is always the right one and
	 * backtracking through a long run of spaces buys nothing.
	 */
	private const DISK_TOKEN_PATTERN = '/^(?>(?:[ \t>]|(?:[-*+]|\d{1,9}[.)])[ \t]+)*)'
		. '\[\[disk (?:image|file|video) objectId=(\d+)((?:[ \t]+[a-z]+=[^\s\]]+)*)\]\][ \t]*$/mu';

	/**
	 * Fenced code block, closed or running to the end of the document (CommonMark allows both). Its
	 * content is text, not markup: the editor's tokenizer never looks inside a fence, so a token shown
	 * as a code sample must not attach the file it names.
	 */
	private const FENCED_CODE_PATTERN = '/^[ \t]*(`{3,}|~{3,})[^\n]*\n.*?(?:^[ \t]*\1[ \t]*$|\z)/msu';

	/** Presentation attributes of a Disk token, mirroring KNOWN_ASSET_ATTRS (note-asset-parser.js). */
	private const DISK_ATTR_PATTERN = '/([a-z]+)=([^\s\]]+)/';

	/**
	 * @return int[] Distinct fileIds found inside any `[[...]]` token or enriched `{...}`
	 *               attribute block in $markdown.
	 */
	public static function extractFileIds(string $markdown): array
	{
		return self::extractFileIdsFromBodies($markdown);
	}

	/**
	 * Disk object ids of `[[disk ...]]` attachments — files owned by Disk, not by this module.
	 *
	 * Kept strictly separate from {@see extractFileIds()}: the two id spaces overlap numerically, and
	 * letting a Disk object id reach the reachability calculation would have the cleanup agent delete
	 * somebody else's file as an orphan.
	 *
	 * Strict where the fileId scan is permissive, because the asymmetry is the other way round. A missed
	 * fileId deletes a file; a missed Disk object only detaches a file this document borrowed, and the
	 * text keeps naming it. A spurious one, on the other hand, ATTACHES somebody else's file to the
	 * document — which is what opens it to everyone who reads the document — so `[[widget objectId=5]]`
	 * or a `{objectId=5}` in prose must count for nothing. There is no looser legacy shape to allow for
	 * either: the enriched `{...}` form predates this feature by years and never named a Disk object.
	 *
	 * Strict therefore means the editor's own grammar, not a relaxed version of it: only what the editor
	 * renders as an attachment is an attachment. What the editor treats as text — a token in the middle
	 * of a sentence, an unterminated one, one inside a fenced code block, one carrying an attribute the
	 * editor does not know — yields nothing here, or a code sample in a document would quietly hand its
	 * readers a file.
	 *
	 * @return int[]
	 */
	public static function extractDiskObjectIds(string $markdown): array
	{
		if ($markdown === '')
		{
			return [];
		}

		$markup = (string)preg_replace(self::FENCED_CODE_PATTERN, '', $markdown);
		if (!preg_match_all(self::DISK_TOKEN_PATTERN, $markup, $matches, PREG_SET_ORDER))
		{
			return [];
		}

		$ids = [];
		foreach ($matches as $match)
		{
			if (self::attributesAreCanonical($match[2] ?? ''))
			{
				$ids[] = (int)$match[1];
			}
		}

		return IdNormalizer::normalize($ids);
	}

	/**
	 * An unknown attribute, or a value out of range, makes the whole token non-canonical: the editor
	 * rejects it and shows the line as text, so it names no attachment either.
	 */
	private static function attributesAreCanonical(string $attributes): bool
	{
		if (!preg_match_all(self::DISK_ATTR_PATTERN, $attributes, $matches, PREG_SET_ORDER))
		{
			return true;
		}

		foreach ($matches as [, $key, $value])
		{
			$isValid = match ($key)
			{
				// Width is a percentage of the container; the editor clamps anything over 100 instead of
				// rejecting the token, so an out-of-range number still names an attachment.
				'width' => is_numeric($value) && (float)$value > 0,
				'align' => in_array($value, ['left', 'right', 'center'], true),
				default => false,
			};

			if (!$isValid)
			{
				return false;
			}
		}

		return true;
	}

	/**
	 * @return int[]
	 */
	private static function extractFileIdsFromBodies(string $markdown): array
	{
		if ($markdown === '')
		{
			return [];
		}

		$ids = [];
		foreach ([self::TOKEN_PATTERN, self::ENRICHED_ATTRS_PATTERN] as $bodyPattern)
		{
			if (!preg_match_all($bodyPattern, $markdown, $bodyMatches))
			{
				continue;
			}

			foreach ($bodyMatches[1] as $body)
			{
				if (preg_match_all(self::FILE_ID_PATTERN, $body, $idMatches))
				{
					foreach ($idMatches[1] as $idString)
					{
						$ids[] = (int)$idString;
					}
				}
			}
		}

		return IdNormalizer::normalize($ids);
	}
}
