<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Import\Transformer\Landing\BlockConverter;

use Bitrix\Note\Internal\Integration\Landing\FileReader;
use Bitrix\Note\Internal\Service\Import\Source\LandingSource;
use Bitrix\Note\Internal\Service\Import\Transformer\Landing\HtmlToMarkdown;

/**
 * Block: 49.1.video_just_video and any block with .landing-block-node-embed.
 *
 * - Whitelist embeds (YouTube/Vimeo/RuTube/VK) -> emit the video URL on its own paragraph.
 *   The note editor's embed extension picks it up and turns the paragraph into a player.
 * - Locally stored videos -> enriched-asset placeholder `![alt](landing-file://N)`.
 * - Everything else -> the source kept by the one link rule ({@see HtmlToMarkdown::linkOrText()}):
 *   a link where the document may carry one, the address itself where it may not (Q-4 fallback).
 *
 * The canonical page URL wins over the embed URL wherever landing stores both
 * ({@see videoUrl()}): `ui.video-service` recognizes the embed form of YouTube only, so a Vimeo,
 * RuTube or VK player URL would reach the document as bare text instead of a video. A block that
 * carries the embed URL alone (legacy content saved before landing added the canonical attribute)
 * gets its page URL rebuilt out of the player URL ({@see canonicalUrlFromEmbed()}).
 */
final class VideoConverter implements Converter
{
	/**
	 * Nodes that can carry the video URL, most specific first. Landing keeps it on the marker element
	 * itself: 49.1.video_just_video on `.landing-block-node-embed`, the remaining video blocks on
	 * `.landing-block-node-video`.
	 */
	private const VIDEO_NODE_SELECTORS = ['landing-block-node-embed', 'landing-block-node-video'];

	/**
	 * Leading part every rule of {@see canonicalUrlFromEmbed()} shares: the scheme, which is optional
	 * and captured so the rebuilt address keeps the one it came with, and an optional `www.`.
	 */
	private const EMBED_URL_PREFIX = '(?<scheme>(?:https?:)?//)?(?:www\.)?';

	/** A scheme at the head of an address, whatever that scheme is - see {@see fullUrl()}. */
	private const URL_SCHEME_PATTERN = '~^[a-z][a-z0-9+.\-]*:~i';

	private const EMBED_WHITELIST = [
		'youtube.com',
		'youtu.be',
		'youtube-nocookie.com',
		'vimeo.com',
		'player.vimeo.com',
		'rutube.ru',
		'vk.com',
		'vk.ru',
		'vkvideo.ru',
	];

	public function __construct(private readonly FileReader $files = new FileReader())
	{
	}

	public function convert(array $block): string
	{
		$content = (string)($block['CONTENT'] ?? '');
		$embedHtml = BlockHtmlExtractor::extractBySelector($content, 'landing-block-node-embed');

		$src = $this->videoUrl($content, $embedHtml);

		$sourceParams = is_array($block['SOURCE_PARAMS'] ?? null) ? $block['SOURCE_PARAMS'] : [];
		if ($src === '' && isset($sourceParams['SRC']))
		{
			$src = trim((string)$sourceParams['SRC']);
		}

		$blockId = (int)($block['ID'] ?? 0);
		$landingFileIds = $blockId > 0 ? $this->files->filesForBlock($blockId) : [];
		if (!empty($landingFileIds))
		{
			$landingFileId = $landingFileIds[0];
			$alt = HtmlToMarkdown::escapeImageAlt($this->extractAlt($embedHtml ?: $content));

			return '![' . $alt . '](' . LandingSource::ATTACHMENT_SCHEME . $landingFileId . ')';
		}

		if ($src === '')
		{
			return '';
		}

		$src = self::canonicalUrlFromEmbed($src);
		if (self::isEmbedWhitelisted($src))
		{
			return $src;
		}

		// The label is escaped as the markdown text it becomes; the href is left to the link rule,
		// which escapes what would end the link target early.
		$label = $this->extractAlt($embedHtml ?: $content) ?: $src;

		return HtmlToMarkdown::linkOrText(BlockHtmlExtractor::escapeMarkdownInline($label), $src);
	}

	/**
	 * URL of the block's video, canonical page URL first.
	 *
	 * Landing stores both forms as attributes of the video marker element: `data-source` holds the URL
	 * the user pasted (`vimeo.com/<id>`), `data-src` the player URL the editor derived from it
	 * (`player.vimeo.com/video/<id>?...`) - see landing `Node\Embed::saveNode()`. An `<iframe src>` or
	 * `<a href>` inside the node covers markup that carries a ready-made embed instead of the
	 * attributes, and it is read last: the attributes are where the editor writes the user's choice.
	 *
	 * @param string $embedHtml inner HTML of `.landing-block-node-embed` (its own attributes are not
	 *   part of it, hence the separate attribute pass over $content)
	 */
	private function videoUrl(string $content, string $embedHtml): string
	{
		foreach (self::VIDEO_NODE_SELECTORS as $selector)
		{
			$nodes = BlockHtmlExtractor::extractElementAttributes($content, $selector, ['data-source', 'data-src']);
			foreach ($nodes as $attrs)
			{
				$src = $attrs['data-source'] !== '' ? $attrs['data-source'] : $attrs['data-src'];
				if ($src !== '')
				{
					return $src;
				}
			}
		}

		if ($embedHtml === '')
		{
			return '';
		}

		$src = BlockHtmlExtractor::extractFirstAttribute($embedHtml, 'iframe', 'src');

		return $src !== '' ? $src : BlockHtmlExtractor::extractFirstAttribute($embedHtml, 'a', 'href');
	}

	/**
	 * Address of the page a video lives on, rebuilt out of the address of its player.
	 *
	 * The editor recognizes a video by the shape of its address (`ui.video-service` matchers), and the
	 * player shapes of Vimeo, RuTube and VK match none of them: a block whose only stored address is
	 * the player one - content saved before landing started keeping the pasted address in
	 * `data-source` - would arrive as bare text. Each rule below is the inverse of what the matcher of
	 * that host builds, so the rebuilt address is the one the matcher itself would have started from.
	 * Any other address comes back untouched: a shape that cannot be inverted without guessing keeps
	 * the behaviour it had.
	 *
	 * The scheme is carried over exactly as it came, down to its absence - the block tokenizer of the
	 * editor takes the scheme-relative form too (video-embed-parser.js `BARE_URL_LINE`). The playback
	 * parameters of the player URL are dropped: the matchers build a player URL out of the id alone,
	 * so nothing downstream would read them back.
	 */
	private static function canonicalUrlFromEmbed(string $url): string
	{
		// player.vimeo.com/video/76979871?autoplay=0 -> vimeo.com/76979871
		if (preg_match('~^' . self::EMBED_URL_PREFIX . 'player\.vimeo\.com/video/(?<id>\d+)~i', $url, $m))
		{
			return $m['scheme'] . 'vimeo.com/' . $m['id'];
		}

		// rutube.ru/play/embed/abc123 -> rutube.ru/video/abc123/
		if (preg_match('~^' . self::EMBED_URL_PREFIX . 'rutube\.ru/play/embed/(?<id>[0-9A-Za-z]+)~i', $url, $m))
		{
			return $m['scheme'] . 'rutube.ru/video/' . $m['id'] . '/';
		}

		// vk.com/video_ext.php?oid=-1&id=2&hd=2 -> vk.com/video-1_2. vk.ru and vkvideo.ru serve the same
		// player and answer to the same page address, and the matcher of the editor knows vk.com, so all
		// three arrive at one host. The owner id is negative for a group or a public page.
		$vkEmbed = '~^' . self::EMBED_URL_PREFIX . '(?:vk\.com|vk\.ru|vkvideo\.ru)/video_ext\.php\?(?<query>\S*)~i';
		if (preg_match($vkEmbed, $url, $m))
		{
			parse_str($m['query'], $params);
			$ownerId = (string)($params['oid'] ?? '');
			$videoId = (string)($params['id'] ?? '');

			if (preg_match('~^-?\d+$~', $ownerId) && preg_match('~^\d+$~', $videoId))
			{
				return $m['scheme'] . 'vk.com/video' . $ownerId . '_' . $videoId;
			}
		}

		return $url;
	}

	private function extractAlt(string $html): string
	{
		$text = trim(strip_tags(html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8')));

		return preg_replace("/\s+/u", ' ', $text) ?? '';
	}

	/**
	 * Single source of truth for the embed host whitelist, reused by HtmlToMarkdown when it
	 * turns an inline <iframe> into a standalone embed URL.
	 *
	 * The address is completed before its host is read ({@see fullUrl()}), so no caller has to
	 * remember to do it: every one of them hands over an address landing stored the way it was
	 * written, and that address may carry no scheme.
	 */
	public static function isEmbedWhitelisted(string $url): bool
	{
		$host = mb_strtolower((string)parse_url(self::fullUrl($url), PHP_URL_HOST));
		if ($host === '')
		{
			return false;
		}

		foreach (self::EMBED_WHITELIST as $allowed)
		{
			if ($host === $allowed || str_ends_with($host, '.' . $allowed))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * The address written in full, so its host is read as a host and not as the first step of a path:
	 * `https:` ahead of a scheme-relative address (`//player.vimeo.com/video/<id>` - the form the editor
	 * of landing writes), `https://` ahead of a bare one (`vimeo.com/<id>` - the form an author typing
	 * an address by hand leaves, since the matchers landing checks it against ask for no scheme).
	 *
	 * Completing the address is not the same as comparing it loosely: the host is still the whole
	 * authority of the address and still has to be one of the whitelist, so an address of a foreign
	 * origin carrying a whitelisted host in its path is answered the way it always was.
	 *
	 * Only what goes into the check is completed. The address that goes into the document keeps the
	 * form it came with - the tokenizer of the editor reads the scheme-relative form too
	 * (video-embed-parser.js `BARE_URL_LINE`), so rewriting the stored value would gain nothing and
	 * change what the page kept. An address that carries a scheme of its own is left alone, including
	 * one the check has to answer no to (`file:`, `data:`), and so is a path of the portal, which
	 * names no host at all.
	 */
	private static function fullUrl(string $url): string
	{
		if ($url === '' || preg_match(self::URL_SCHEME_PATTERN, $url))
		{
			return $url;
		}

		if (str_starts_with($url, '//'))
		{
			return 'https:' . $url;
		}

		return str_starts_with($url, '/') ? $url : 'https://' . $url;
	}
}
