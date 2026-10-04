<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Link;

use Bitrix\Main\Engine\UrlManager;
use Bitrix\Note\Internal\Configuration;

/**
 * [P2.T1] The only place on the backend that decides what counts as a link from one document to
 * another. A pure function of the stored markdown: nothing here reads the database, so an id it
 * returns is a written intent, not a document that is known to exist.
 *
 * Two shapes are recognised — the canonical document mention token `@{document:<id>}` and a
 * markdown link whose address resolves to the document route. The address rules mirror
 * parseInternalNoteLink() in install/js/note/editor/src/utils/internal-link.js one for one; the two
 * implementations are held together by the shared case list in tests/fixtures/internal-link-cases.json.
 */
final class DocumentLinkExtractor
{
	private const MENTION_TOKEN_PATTERN = '/@\{([a-z]+):(\d+)\}/u';

	// The negative lookbehind keeps image syntax out: `![alt](url)` addresses a file, never a document.
	private const MARKDOWN_LINK_PATTERN = '/(?<!!)\[[^\]]*\]\(([^)]*)\)/u';

	private const MENTION_TYPE_DOCUMENT = 'document';

	// `/note` is optional: the SPA is mounted on base `/note/`, so a user-typed `/document/{id}/`
	// denotes the same document. Collection routes (`/workspace/{id}/` and its dead-but-stored
	// `/collection/{id}/` alias) deliberately have no pattern here — they simply are not documents.
	private const DOCUMENT_PATH_PATTERN = '~^(?:/note)?/document/(\d+)/?$~';

	// [B1] Code context is not link context: a token or address inside `code` or a fenced block is a
	// literal, not a reference, so it is blanked out before the link regexes run. Fenced blocks first
	// (a per-line scan, see stripFencedCode), then inline spans in what is left.
	//
	// A fence opens with >= 3 backticks or tildes (up to 3 leading spaces). Its closing fence uses the
	// SAME character and a length GREATER THAN OR EQUAL TO the opening one (CommonMark), so the length
	// cannot be pinned with a backreference — an opening ``` closed by ```` would slip past it and the
	// text after the block would be lost. An inline span, on the other hand, closes on EXACTLY as many
	// backticks as it opened, so its backreference is correct.
	private const FENCE_OPEN_PATTERN = '/^[ \t]{0,3}(`{3,}|~{3,})/';
	private const INLINE_CODE_PATTERN = '/(`+)[\s\S]*?\1/';

	private ?string $resolvedOrigin = null;

	/**
	 * @param string|null $origin Portal origin an absolute address is matched against; resolved from
	 *                            the current request when omitted.
	 */
	public function __construct(private readonly ?string $origin = null)
	{
	}

	/**
	 * @return int[] unique target ids, self-reference excluded
	 */
	public function extract(string $markdown, int $sourceId): array
	{
		if ($markdown === '')
		{
			return [];
		}

		$scannable = $this->withoutCode($markdown);

		$targets = [];
		foreach ($this->mentionTargets($scannable) as $targetId)
		{
			$targets[$targetId] = true;
		}
		foreach ($this->linkTargets($scannable) as $targetId)
		{
			$targets[$targetId] = true;
		}

		unset($targets[$sourceId]);

		$targetIds = array_keys($targets);

		// [B3] One document cannot pull an unbounded number of others into the index: keep the first N
		// unique targets (insertion order) and log the rest off, so a mass paste cannot flood one INSERT.
		if (count($targetIds) > Configuration::MAX_DOCUMENT_LINK_TARGETS)
		{
			$this->logTargetOverflow($sourceId, count($targetIds));
			$targetIds = array_slice($targetIds, 0, Configuration::MAX_DOCUMENT_LINK_TARGETS);
		}

		return $targetIds;
	}

	/**
	 * Blanks out code context so its contents are not mistaken for links.
	 */
	private function withoutCode(string $markdown): string
	{
		$withoutFenced = $this->stripFencedCode($markdown);

		$withoutInline = preg_replace(self::INLINE_CODE_PATTERN, '', $withoutFenced);

		return $withoutInline ?? $withoutFenced;
	}

	/**
	 * Drops every fenced code block, keeping the line structure so the surrounding text (and its links)
	 * stays put. A block runs from its opening fence to the first closing fence of the same character
	 * whose length is at least the opening one; an unclosed fence runs to the end of the document.
	 */
	private function stripFencedCode(string $markdown): string
	{
		$out = [];
		$fenceChar = null;
		$fenceLen = 0;
		foreach (explode("\n", $markdown) as $line)
		{
			if ($fenceChar === null)
			{
				if (preg_match(self::FENCE_OPEN_PATTERN, $line, $match))
				{
					$fenceChar = $match[1][0];
					$fenceLen = strlen($match[1]);
					$out[] = '';

					continue;
				}

				$out[] = $line;

				continue;
			}

			if ($this->isClosingFence($line, $fenceChar, $fenceLen))
			{
				$fenceChar = null;
				$fenceLen = 0;
			}

			// Both the body and the closing fence line are code, never link context.
			$out[] = '';
		}

		return implode("\n", $out);
	}

	/**
	 * A closing fence is a run of at least $openLen of $fenceChar, up to 3 leading spaces and only
	 * whitespace after it — no info string.
	 */
	private function isClosingFence(string $line, string $fenceChar, int $openLen): bool
	{
		$pattern = '/^[ ]{0,3}' . preg_quote($fenceChar, '/') . '{' . $openLen . ',}[ \t\r]*$/';

		return (bool)preg_match($pattern, $line);
	}

	private function logTargetOverflow(int $sourceId, int $found): void
	{
		\CEventLog::Add([
			'SEVERITY' => \CEventLog::SEVERITY_WARNING,
			'AUDIT_TYPE_ID' => 'NOTE_DOCUMENT_LINK_TARGET_OVERFLOW',
			'MODULE_ID' => 'note',
			'ITEM_ID' => $sourceId,
			'DESCRIPTION' => 'source ' . $sourceId . ': ' . $found . ' outgoing links capped to '
				. Configuration::MAX_DOCUMENT_LINK_TARGETS,
		]);
	}

	/**
	 * @return int[]
	 */
	private function mentionTargets(string $markdown): array
	{
		if (!preg_match_all(self::MENTION_TOKEN_PATTERN, $markdown, $matches, PREG_SET_ORDER))
		{
			return [];
		}

		$ids = [];
		foreach ($matches as $match)
		{
			if ($match[1] !== self::MENTION_TYPE_DOCUMENT)
			{
				continue;
			}

			$id = (int)$match[2];
			if ($id > 0)
			{
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * @return int[]
	 */
	private function linkTargets(string $markdown): array
	{
		if (!preg_match_all(self::MARKDOWN_LINK_PATTERN, $markdown, $matches))
		{
			return [];
		}

		$ids = [];
		foreach ($matches[1] as $rawHref)
		{
			$id = $this->documentIdFromHref($this->normalizeHref($rawHref));
			if ($id !== null)
			{
				$ids[] = $id;
			}
		}

		return $ids;
	}

	/**
	 * Strips what markdown wraps around the address itself: the optional `"title"` tail and the
	 * angle brackets of the `<...>` form.
	 */
	private function normalizeHref(string $rawHref): string
	{
		$href = trim($rawHref);

		$titleAt = strcspn($href, " \t\n");
		if ($titleAt < strlen($href))
		{
			$href = substr($href, 0, $titleAt);
		}

		if (str_starts_with($href, '<') && str_ends_with($href, '>'))
		{
			$href = substr($href, 1, -1);
		}

		return $href;
	}

	private function documentIdFromHref(string $href): ?int
	{
		$path = $this->pathname($href);
		if ($path === null || !preg_match(self::DOCUMENT_PATH_PATTERN, $path, $match))
		{
			return null;
		}

		$id = (int)$match[1];

		return $id > 0 ? $id : null;
	}

	private function pathname(string $href): ?string
	{
		if ($href === '' || str_starts_with($href, '//'))
		{
			return null;
		}

		if (str_starts_with($href, '/'))
		{
			return (string)preg_split('/[?#]/', $href)[0];
		}

		if (!preg_match('~^https?://~i', $href))
		{
			return null;
		}

		$parts = parse_url($href);
		if ($parts === false || ($parts['host'] ?? '') === '')
		{
			return null;
		}

		$currentOrigin = $this->currentOrigin();
		if ($currentOrigin === '' || $this->originOf($parts) !== $currentOrigin)
		{
			return null;
		}

		return $parts['path'] ?? '/';
	}

	/**
	 * @param array<string, mixed> $parts output of parse_url()
	 */
	private function originOf(array $parts): string
	{
		$scheme = mb_strtolower((string)($parts['scheme'] ?? ''));
		$host = mb_strtolower((string)($parts['host'] ?? ''));
		$port = isset($parts['port']) ? (int)$parts['port'] : 0;

		$isDefaultPort = ($scheme === 'http' && $port === 80) || ($scheme === 'https' && $port === 443);
		$suffix = ($port > 0 && !$isDefaultPort) ? ':' . $port : '';

		return $scheme . '://' . $host . $suffix;
	}

	private function currentOrigin(): string
	{
		if ($this->resolvedOrigin !== null)
		{
			return $this->resolvedOrigin;
		}

		$raw = $this->origin ?? $this->portalOrigin();
		$parts = parse_url($raw);
		$this->resolvedOrigin = ($parts === false || ($parts['host'] ?? '') === '')
			? ''
			: $this->originOf($parts)
		;

		return $this->resolvedOrigin;
	}

	private function portalOrigin(): string
	{
		try
		{
			return UrlManager::getInstance()->getHostUrl();
		}
		catch (\Throwable)
		{
			// No request context (agent, CLI): absolute addresses simply stop being recognised,
			// relative ones — the form the editor writes — keep working.
			return '';
		}
	}
}
