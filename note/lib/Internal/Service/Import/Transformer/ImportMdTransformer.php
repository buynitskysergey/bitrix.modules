<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Import\Transformer;

use Bitrix\Main\IO\Path;

abstract class ImportMdTransformer
{
	/**
	 * Returns regex pattern to match attachment references in markdown.
	 * Pattern MUST have named groups: 'linkPart' for `![...]` or `[...]`,
	 * 'url' for the full URL, 'attachmentId' for the attachment UUID.
	 */
	abstract protected function getPattern(): string;

	/**
	 * Pre-processes source-specific markdown constructs before saving.
	 * Override in subclasses for source-specific transformations.
	 */
	public function preprocessMarkdown(string $markdown): string
	{
		return $markdown;
	}

	/**
	 * Source-specific hook called by the last step of the attachment chain: whatever placeholder the
	 * download and the retry could not resolve has to leave the chain as something a reader can use.
	 * Default: identity - a source whose placeholder is an address that stays usable on its own
	 * (Outline) has nothing to unwrap.
	 */
	public function unwrapUnresolvedAttachments(string $markdown): string
	{
		return $markdown;
	}

	/**
	 * Extracts all attachment IDs from markdown text.
	 *
	 * @return string[]
	 */
	public function extractAttachmentIds(string $markdown): array
	{
		if (!preg_match_all($this->getPattern(), $markdown, $matches, PREG_SET_ORDER))
		{
			return [];
		}

		$ids = [];
		foreach ($matches as $match)
		{
			$key = $this->attachmentKey($match);
			if ($key !== '')
			{
				$ids[] = $key;
			}
		}

		return array_values(array_unique($ids));
	}

	/**
	 * Resolves the download/lookup key for a single attachment match. Defaults to the
	 * `attachmentId` named group; subclasses that surface more than one placeholder scheme
	 * (e.g. local files vs external image URLs) override this to pick the right group.
	 *
	 * @param array<string|int, string> $matches one match from the {@see getPattern()} regex
	 */
	protected function attachmentKey(array $matches): string
	{
		return (string)($matches['attachmentId'] ?? '');
	}

	/**
	 * Replaces attachment URLs with enriched asset syntax using pre-downloaded file data.
	 *
	 * @param string $markdown Source markdown
	 * @param int $documentId Bitrix document ID
	 * @param array<string, array{fileId: int, name: string, size: int, mimeType: string}> $attachmentFileMap
	 */
	public function transform(string $markdown, int $documentId, array $attachmentFileMap): TransformResult
	{
		$fileIds = [];

		// Assets that sit inside a markdown table row must render *inline in their cell*: the note
		// editor stores in-cell attachments as `![](type){type=.. fileId=.. documentId=.. ..}`
		// (enriched-asset-parser.js parseEnrichedAssetCell). The block `[[image fileId=N]]` token
		// used everywhere else would be pulled to column 0 by splitInlineAssets() below and shatter
		// the row, so table-row placeholders are rewritten to the enriched inline form up front.
		// Their rewritten URL is no longer a landing/api placeholder, so the main pass skips them.
		$markdown = $this->enrichTableRowAssets($markdown, $documentId, $attachmentFileMap, $fileIds);

		$result = preg_replace_callback(
			$this->getPattern(),
			function (array $matches) use ($documentId, $attachmentFileMap, &$fileIds): string {
				$attachmentId = $this->attachmentKey($matches);
				if ($attachmentId === '' || !isset($attachmentFileMap[$attachmentId]))
				{
					return $matches[0];
				}

				$file = $attachmentFileMap[$attachmentId];
				$fileIds[] = $file['fileId'];

				$type = $this->resolveType($file['mimeType']);
				$attrsSuffix = $this->buildAssetAttrs($type, $matches['attrs'] ?? '');

				return sprintf('[[%s fileId=%d%s]]', $type, $file['fileId'], $attrsSuffix);
			},
			$markdown,
		);

		$result = $this->splitInlineAssets($result ?? $markdown);

		return new TransformResult($result, array_unique($fileIds));
	}

	/**
	 * Rewrites attachment placeholders that sit inside a GFM table row to the enriched inline asset
	 * syntax the note editor uses for in-cell attachments (enriched-asset-parser.js). Only bordered
	 * rows (`| ... |`) are touched: both the card table and real landing tables render that way, while
	 * prose with a stray pipe does not, and the block asset token would otherwise be lifted out of
	 * the row by {@see splitInlineAssets()} and break the table. Non-table markdown is untouched.
	 *
	 * @param array<string, array{fileId: int, name: string, size: int, mimeType: string}> $attachmentFileMap
	 * @param int[] $fileIds collected file ids, by reference - same contract as {@see transform()}
	 */
	private function enrichTableRowAssets(
		string $markdown,
		int $documentId,
		array $attachmentFileMap,
		array &$fileIds,
	): string
	{
		if ($markdown === '' || !str_contains($markdown, '|'))
		{
			return $markdown;
		}

		$lines = explode("\n", $markdown);
		foreach ($lines as $index => $line)
		{
			// Bordered GFM row: up to 3 leading spaces, a leading `|`, and a trailing `|`.
			if (!preg_match('/^ {0,3}\|.*\|[ \t]*$/', $line))
			{
				continue;
			}

			$lines[$index] = preg_replace_callback(
				$this->getPattern(),
				function (array $matches) use ($documentId, $attachmentFileMap, &$fileIds): string {
					$attachmentId = $this->attachmentKey($matches);
					if ($attachmentId === '' || !isset($attachmentFileMap[$attachmentId]))
					{
						return $matches[0];
					}

					$file = $attachmentFileMap[$attachmentId];
					$fileIds[] = $file['fileId'];

					return $this->buildInlineAsset($this->resolveType($file['mimeType']), $file, $documentId);
				},
				$line,
			);
		}

		return implode("\n", $lines);
	}

	/**
	 * Builds the enriched inline asset token consumed by the editor's table-cell parser
	 * (enriched-asset-parser.js parseEnrichedAssetCell):
	 *   `![](type){type=.. fileId=.. documentId=.. name=".." size=.. mimeType=".."}`
	 *
	 * The label and URL are cosmetic - the editor resolves the file by fileId + documentId - but the
	 * URL must be non-empty and must not look like a landing/api placeholder (otherwise the main
	 * transform pass would rewrite it), so the asset type word is used. width/align are intentionally
	 * omitted: the cell parser does not read them and a cell sizes to its column.
	 *
	 * @param array{fileId: int, name: string, size: int, mimeType: string} $file
	 */
	private function buildInlineAsset(string $type, array $file, int $documentId): string
	{
		$attrs = [
			'type=' . $type,
			'fileId=' . (int)$file['fileId'],
			'documentId=' . $documentId,
		];

		$name = (string)($file['name'] ?? '');
		if ($name !== '')
		{
			$attrs[] = 'name="' . $this->sanitizeAssetAttr($name) . '"';
		}

		$attrs[] = 'size=' . (int)($file['size'] ?? 0);

		$mimeType = (string)($file['mimeType'] ?? '');
		if ($mimeType !== '')
		{
			$attrs[] = 'mimeType="' . $this->sanitizeAssetAttr($mimeType) . '"';
		}

		$prefix = $type === 'image' ? '!' : '';

		return $prefix . '[](' . $type . '){' . implode(' ', $attrs) . '}';
	}

	/**
	 * Neutralises characters that would truncate or misparse an enriched attr value: the value grammar
	 * is `"[^"]*"` (no backslash-escaping) scanned inside a brace-balanced `{...}`, so a literal quote or
	 * brace has to go, along with newlines that cannot appear inside a table cell.
	 */
	private function sanitizeAssetAttr(string $value): string
	{
		return trim(str_replace(['"', '{', '}', "\r", "\n"], ["'", '(', ')', ' ', ' '], $value));
	}

	/**
	 * Parses a query string like `w=50&a=left` into a token suffix ` width=50 align=left`.
	 * Attributes apply to image/video only; align in {left, right, center}; width in 1..100.
	 */
	private function buildAssetAttrs(string $type, string $attrsQuery): string
	{
		if ($attrsQuery === '' || ($type !== 'image' && $type !== 'video'))
		{
			return '';
		}

		parse_str($attrsQuery, $parsed);
		$parts = [];

		$width = isset($parsed['w']) ? (int)$parsed['w'] : 0;
		if ($width >= 1 && $width <= 100)
		{
			$parts[] = 'width=' . $width;
		}

		$align = isset($parsed['a']) ? (string)$parsed['a'] : '';
		if (in_array($align, ['left', 'right', 'center'], true))
		{
			$parts[] = 'align=' . $align;
		}

		return $parts === [] ? '' : ' ' . implode(' ', $parts);
	}

	/**
	 * Regex for block asset token produced by transform():
	 *   [[image|file|video fileId=N(optional attrs)]]
	 */
	private const ASSET_PATTERN = '/(?<raw>\[\[(?:image|file|video) fileId=\d+(?:[^\]\n]*)\]\])/';

	/**
	 * Isolates every enriched asset as a standalone top-level block: pulled to
	 * column 0 and wrapped in blank lines. The block-level tokenizer only
	 * recognises an asset that owns its line and is not lazily absorbed by a
	 * preceding list item or blockquote, so any list/quote nesting around the
	 * source asset is dropped on purpose. Text around the asset keeps its
	 * structural prefix. Fenced code blocks are left untouched.
	 */
	private function splitInlineAssets(string $markdown): string
	{
		if ($markdown === '' || !str_contains($markdown, '[['))
		{
			return $markdown;
		}

		$lines = explode("\n", $markdown);
		$out = [];
		$inFence = false;
		$fenceMarker = '';

		$pushBlank = static function (array &$out): void {
			if (!empty($out) && trim((string)end($out)) !== '')
			{
				$out[] = '';
			}
		};

		// Set after an asset is pulled out of an indented/list context. The lines
		// that followed that asset belonged to the now-broken item; once the list
		// is gone, any line indented by >=4 spaces would be parsed as an indented
		// code block. De-indent those orphans to column 0 until the next real block.
		$deindentOrphans = false;

		// Set after an asset block is emitted: a blank line must follow it. Honoured
		// lazily so an already-blank source line is reused instead of doubled.
		$pendingSep = false;

		foreach ($lines as $line)
		{
			if ($inFence)
			{
				$out[] = $line;
				if (preg_match('/^[ \t]{0,3}' . preg_quote($fenceMarker, '/') . '[ \t]*$/', $line))
				{
					$inFence = false;
					$fenceMarker = '';
				}
				continue;
			}

			if ($pendingSep)
			{
				if (trim($line) !== '')
				{
					$pushBlank($out);
				}
				$pendingSep = false;
			}

			if ($deindentOrphans)
			{
				if (trim($line) === '')
				{
					$out[] = $line;
					continue;
				}
				if (preg_match('/^(?: {4,}|\t)/', $line))
				{
					$out[] = ltrim($line, " \t");
					continue;
				}
				$deindentOrphans = false;
			}

			if (preg_match('/^[ \t]{0,3}(?<fence>```+|~~~+)/', $line, $fenceMatch))
			{
				$out[] = $line;
				$inFence = true;
				$fenceMarker = $fenceMatch['fence'];
				continue;
			}

			$prefixInfo = $this->analyzeLinePrefix($line);
			$body = substr($line, $prefixInfo['prefixLen']);

			if (!preg_match_all(self::ASSET_PATTERN, $body, $matches, PREG_OFFSET_CAPTURE))
			{
				$out[] = $line;
				continue;
			}

			$segments = [];
			$lastEnd = 0;
			foreach ($matches[0] as $match)
			{
				[$rawAsset, $offset] = $match;
				$before = trim(substr($body, $lastEnd, $offset - $lastEnd));
				if ($before !== '')
				{
					$segments[] = ['text', $before];
				}
				$segments[] = ['asset', $rawAsset];
				$lastEnd = $offset + strlen($rawAsset);
			}
			$tail = trim(substr($body, $lastEnd));
			if ($tail !== '')
			{
				$segments[] = ['text', $tail];
			}

			if (empty($segments))
			{
				$out[] = $line;
				continue;
			}

			$first = true;
			foreach ($segments as [$type, $text])
			{
				if ($type === 'asset')
				{
					$pushBlank($out);
					$out[] = $text;
					$pendingSep = true;
				}
				else
				{
					if ($pendingSep)
					{
						$pushBlank($out);
						$pendingSep = false;
					}
					$out[] = ($first ? $prefixInfo['firstPrefix'] : $prefixInfo['contPrefix']) . $text;
				}
				$first = false;
			}

			// Pure asset line lifted out of a list/indent: its trailing siblings
			// may now be orphaned indented continuations.
			if ($prefixInfo['prefixLen'] > 0 && count($segments) === 1 && $segments[0][0] === 'asset')
			{
				$deindentOrphans = true;
			}
		}

		// Trailing blank lines from the per-asset separator carry no meaning in markdown.
		return rtrim(implode("\n", $out), "\n");
	}

	/**
	 * Splits a line's leading structural prefix (blockquote chain + indent +
	 * list marker) from its content body. Returns prefixes used to emit
	 * subsequent split segments while preserving the original block context.
	 *
	 * @return array{prefixLen:int, firstPrefix:string, contPrefix:string}
	 */
	private function analyzeLinePrefix(string $line): array
	{
		preg_match(
			'/^(?<bq>(?:[ \t]*>+[ \t]*)*)(?<indent>[ \t]*)(?<marker>(?:\d+[.)]|[-+*])[ \t]+)?/',
			$line,
			$m,
		);
		$bq = $m['bq'] ?? '';
		$indent = $m['indent'] ?? '';
		$marker = $m['marker'] ?? '';

		$markerAsSpaces = $marker === '' ? '' : str_repeat(' ', strlen($marker));

		return [
			'prefixLen' => strlen($bq) + strlen($indent) + strlen($marker),
			'firstPrefix' => $bq . $indent . $marker,
			'contPrefix' => $bq . $indent . $markerAsSpaces,
		];
	}

	protected function resolveType(string $mimeType): string
	{
		if (str_starts_with($mimeType, 'image/'))
		{
			return 'image';
		}

		if (str_starts_with($mimeType, 'video/'))
		{
			return 'video';
		}

		return 'file';
	}

	protected function detectMimeType(string $fileName): string
	{
		$ext = mb_strtolower(Path::getExtension($fileName));

		return self::MIME_MAP[$ext] ?? 'application/octet-stream';
	}

	protected const MIME_MAP = [
		'jpg' => 'image/jpeg',
		'jpeg' => 'image/jpeg',
		'png' => 'image/png',
		'gif' => 'image/gif',
		'webp' => 'image/webp',
		'svg' => 'image/svg+xml',
		'mp4' => 'video/mp4',
		'webm' => 'video/webm',
		'mov' => 'video/quicktime',
		'pdf' => 'application/pdf',
		'csv' => 'text/csv',
		'json' => 'application/json',
		'md' => 'text/markdown',
		'txt' => 'text/plain',
		'doc' => 'application/msword',
		'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
		'xls' => 'application/vnd.ms-excel',
		'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
		'zip' => 'application/zip',
	];
}
