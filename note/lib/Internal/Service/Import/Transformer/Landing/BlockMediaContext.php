<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Import\Transformer\Landing;

/**
 * Per-block cursor over the block's landing FILE_IDs. Images in the block HTML consume
 * FILE_IDs in document order (matching the index-based binding used by ImageConverter/
 * GalleryConverter). One instance is created per block and passed into every
 * HtmlToMarkdown::convert call for that block (e.g. across MultiColumn columns) so the
 * cursor is not reset.
 */
final class BlockMediaContext
{
	private int $cursor = 0;

	/** @param int[] $landingFileIds block FILE_IDs in deterministic order */
	public function __construct(private readonly array $landingFileIds)
	{
	}

	/**
	 * Builds a cursor whose pool excludes the FILE_IDs already inlined on the block's media via
	 * `data-fileid` / `data-fileid2x`. Those managed images are resolved straight from the attribute
	 * by {@see HtmlToMarkdown::renderImage()} - which prefers the attribute and never advances the
	 * cursor - so their ids (and their retina @2x siblings) must not ALSO be handed out positionally
	 * to a later data-fileid-less image. Otherwise a block that mixes managed photos (data-fileid)
	 * with external/stock ones re-emits an already-used picture, or its retina duplicate, instead of
	 * the external image (the 28.x team card / avatar regression).
	 *
	 * @param int[] $landingFileIds block FILE_IDs in deterministic order
	 */
	public static function forContent(array $landingFileIds, string $content): self
	{
		if ($landingFileIds === [] || $content === '' || stripos($content, 'data-fileid') === false)
		{
			return new self($landingFileIds);
		}

		if (!preg_match_all('/\bdata-fileid(?:2x)?\s*=\s*["\'](\d+)["\']/i', $content, $m))
		{
			return new self($landingFileIds);
		}

		$inlined = array_map('intval', $m[1]);
		$remaining = array_values(array_filter(
			$landingFileIds,
			static fn(int $id): bool => !in_array($id, $inlined, true),
		));

		return new self($remaining);
	}

	public function nextFileId(): ?int
	{
		return $this->landingFileIds[$this->cursor++] ?? null;
	}

	public function hasFiles(): bool
	{
		return $this->landingFileIds !== [];
	}
}
