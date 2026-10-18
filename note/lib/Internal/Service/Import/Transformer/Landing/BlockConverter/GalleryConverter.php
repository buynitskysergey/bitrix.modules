<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Import\Transformer\Landing\BlockConverter;

use Bitrix\Note\Internal\Integration\Landing\FileReader;
use Bitrix\Note\Internal\Service\Import\Source\LandingSource;
use Bitrix\Note\Internal\Service\Import\Transformer\Landing\HtmlToMarkdown;

/**
 * Block codes matching /^\d+\.\d+\.(gallery|slider)/ - image and embed collections.
 *
 * Each image is rendered as a standalone enriched-asset line so the editor
 * tokenizer treats it as block-level. Files are resolved via b_landing_file
 * (block <-> files) - first DOM image binds to the first FILE_ID and so on; an image with no file
 * behind it goes out wrapped for download ({@see LandingSource::externalAttachmentUrl()}).
 * Carousel embeds (iframes / anchors inside .landing-block-node-embed) follow
 * the images: whitelisted hosts emit a bare URL the editor turns into a video
 * embed, everything else goes out by the one link rule ({@see HtmlToMarkdown::linkOrText()})
 * so the source is never lost.
 */
final class GalleryConverter implements Converter
{
	public function __construct(private readonly FileReader $files = new FileReader())
	{
	}

	public function convert(array $block): string
	{
		$content = (string)($block['CONTENT'] ?? '');

		$lines = array_merge(
			$this->imageLines($block, $content),
			$this->embedLines($content),
		);

		return implode("\n\n", $lines);
	}

	/**
	 * @return string[] one enriched-asset image line per DOM <img>, in document order.
	 */
	private function imageLines(array $block, string $content): array
	{
		$descriptors = BlockHtmlExtractor::extractImageDescriptors($content);
		if (empty($descriptors))
		{
			return [];
		}

		$blockId = (int)($block['ID'] ?? 0);
		$landingFileIds = $blockId > 0 ? $this->files->filesForBlock($blockId) : [];

		$lines = [];
		foreach ($descriptors as $i => $img)
		{
			$landingFileId = $landingFileIds[$i] ?? null;
			$suffix = BlockHtmlExtractor::positionQuerySuffix($img['width'], $img['align']);
			// No b_landing_file behind this <img>: fetch it as an external image when it is an
			// http(s) URL, otherwise keep the raw src untouched - same as ImageConverter. Without the
			// wrapper the gallery pictures of a delivery block stay on their original CDN and never
			// become portal files.
			$url = ($landingFileId !== null
				? LandingSource::ATTACHMENT_SCHEME . $landingFileId
				: (LandingSource::externalAttachmentUrl($img['src']) ?? $img['src'])) . $suffix;

			$lines[] = '![' . HtmlToMarkdown::escapeImageAlt($img['alt']) . '](' . $url . ')';
		}

		return $lines;
	}

	/**
	 * @return string[] one line per .landing-block-node-embed: a bare URL for
	 *   whitelisted hosts, otherwise the source kept by the one link rule - a link where the
	 *   document may carry one, the address itself where it may not.
	 */
	private function embedLines(string $content): array
	{
		$lines = [];
		foreach (BlockHtmlExtractor::extractAllBySelector($content, 'landing-block-node-embed') as $embedHtml)
		{
			$src = BlockHtmlExtractor::extractFirstAttribute($embedHtml, 'iframe', 'src');
			if ($src === '')
			{
				$src = BlockHtmlExtractor::extractFirstAttribute($embedHtml, 'a', 'href');
			}
			if ($src === '')
			{
				continue;
			}

			$lines[] = VideoConverter::isEmbedWhitelisted($src)
				? $src
				: HtmlToMarkdown::linkOrText(BlockHtmlExtractor::escapeMarkdownInline($src), $src);
		}

		return $lines;
	}
}
