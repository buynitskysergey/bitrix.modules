<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

class ChecklistTitlePresenter
{
	private readonly ChecklistTitleBbCodeFormatter $bbCodeFormatter;
	private readonly ChecklistTitleUrlFormatter $urlFormatter;

	public function __construct(
		?ChecklistTitleBbCodeFormatter $bbCodeFormatter = null,
		?ChecklistTitleUrlFormatter $urlFormatter = null,
	)
	{
		$this->bbCodeFormatter = $bbCodeFormatter ?? new ChecklistTitleBbCodeFormatter();
		$this->urlFormatter = $urlFormatter ?? new ChecklistTitleUrlFormatter();
	}

	public function toPlainText(string $sourceText): string
	{
		return $this->formatForIm(
			sourceText: $sourceText,
			preserveUrlTags: true,
			protectRawUrls: false,
		);
	}

	public function toNonClickablePlainText(string $sourceText): string
	{
		return $this->formatForIm(
			sourceText: $sourceText,
			preserveUrlTags: false,
			protectRawUrls: true,
			preserveInlineTags: true,
		);
	}

	public function toActionLinkPlainText(string $sourceText): string
	{
		return $this->formatForIm(
			sourceText: $sourceText,
			preserveUrlTags: false,
			protectRawUrls: false,
			preserveInlineTags: false,
		);
	}

	public function containsLink(string $sourceText): bool
	{
		$plainText = $this->toPlainText($sourceText);

		return $this->bbCodeFormatter->containsUrlTag(
			$sourceText,
			fn (string $url): string => $this->urlFormatter->sanitize($url),
		) || $this->urlFormatter->containsRawUrl($plainText);
	}

	private function formatForIm(
		string $sourceText,
		bool $preserveUrlTags,
		bool $protectRawUrls,
		bool $preserveInlineTags = true,
	): string
	{
		$wholeLinkText = $this->bbCodeFormatter->extractWholeUrlText(
			$sourceText,
			fn (string $url): string => $this->urlFormatter->sanitize($url),
		);

		if ($wholeLinkText !== null)
		{
			return $this->formatPlainText($wholeLinkText, $protectRawUrls);
		}

		$text = htmlspecialchars($sourceText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
		$text = trim($this->bbCodeFormatter->format(
			$text,
			$preserveUrlTags,
			fn (string $url): string => $this->urlFormatter->sanitize($url),
			$preserveInlineTags,
		));

		if ($protectRawUrls)
		{
			return $this->urlFormatter->protectRawUrls($text);
		}

		return $text;
	}

	private function formatPlainText(string $sourceText, bool $protectRawUrls): string
	{
		$text = htmlspecialchars($sourceText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);
		$text = trim($this->bbCodeFormatter->escapeDelimiters($text));

		if ($protectRawUrls)
		{
			return $this->urlFormatter->protectRawUrls($text);
		}

		return $text;
	}
}
