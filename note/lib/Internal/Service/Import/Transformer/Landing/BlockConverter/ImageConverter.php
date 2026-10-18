<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Import\Transformer\Landing\BlockConverter;

use Bitrix\Note\Internal\Integration\Landing\FileReader;
use Bitrix\Note\Internal\Service\Import\Source\LandingSource;
use Bitrix\Note\Internal\Service\Import\Transformer\Landing\HtmlToMarkdown;

/**
 * Block: 32.2.img_one_big and any block whose .landing-block-node-img produces an <img>.
 *
 * Emits markdown image syntax `![alt](landing-file://<landingFileId>)` when a
 * b_landing_file row exists for the block; otherwise falls back to the source URL.
 * Download step resolves `landing-file://N` IDs through ImportFileService::copyFromLanding.
 */
final class ImageConverter implements Converter
{
	public function __construct(private readonly FileReader $files = new FileReader())
	{
	}

	public function convert(array $block): string
	{
		$content = (string)($block['CONTENT'] ?? '');
		$descriptors = BlockHtmlExtractor::extractImageDescriptors(
			BlockHtmlExtractor::extractBySelector($content, 'landing-block-node-img') ?: $content,
		);
		if (empty($descriptors))
		{
			return '';
		}

		$blockId = (int)($block['ID'] ?? 0);
		$landingFileIds = $blockId > 0 ? $this->files->filesForBlock($blockId) : [];

		$lines = [];
		foreach ($descriptors as $i => $img)
		{
			$landingFileId = $landingFileIds[$i] ?? null;
			$suffix = BlockHtmlExtractor::positionQuerySuffix($img['width'], $img['align']);
			if ($landingFileId !== null)
			{
				$target = LandingSource::ATTACHMENT_SCHEME . $landingFileId;
			}
			else
			{
				// No b_landing_file behind this <img>: fetch it as an external image when it is
				// an http(s) URL, otherwise keep the raw src untouched.
				$target = LandingSource::externalAttachmentUrl($img['src']) ?? $img['src'];
			}

			$lines[] = '![' . HtmlToMarkdown::escapeImageAlt($img['alt']) . '](' . $target . $suffix . ')';
		}

		return implode("\n\n", $lines);
	}
}
