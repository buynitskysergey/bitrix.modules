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
 */
final class AssetTokenParser
{
	private const TOKEN_PATTERN = '/\[\[([^\[\]]*)\]\]/u';
	private const ENRICHED_ATTRS_PATTERN = '/\{([^{}\n]*)\}/u';
	private const FILE_ID_PATTERN = '/fileId\s*=\s*"?(\d+)/u';

	/**
	 * @return int[] Distinct fileIds found inside any `[[...]]` token or enriched `{...}`
	 *               attribute block in $markdown.
	 */
	public static function extractFileIds(string $markdown): array
	{
		if ($markdown === '')
		{
			return [];
		}

		$fileIds = [];
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
						$fileIds[] = (int)$idString;
					}
				}
			}
		}

		return IdNormalizer::normalize($fileIds);
	}
}
