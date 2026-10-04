<?php

declare(strict_types=1);

namespace Bitrix\Tasks\V2\Internal\Integration\Im\Action;

class ChecklistTitleBbCodeFormatter
{
	private const URL_TAG = 'URL';
	private const URL_BB_CODE_PATTERN = '#\[URL(?:=[^\]]*)?].*?\[/URL]#is';
	private const WHOLE_URL_BB_CODE_PATTERN = '#^\s*\[URL(?:=([^\]]*))?](.*?)\[/URL]\s*$#is';
	private const ALLOWED_INLINE_TAGS = [
		'B' => true,
		'I' => true,
		'U' => true,
		'S' => true,
	];

	public function containsUrlTag(string $sourceText, ?callable $sanitizeUrl = null): bool
	{
		if ($sanitizeUrl === null)
		{
			return preg_match(self::URL_BB_CODE_PATTERN, $sourceText) === 1;
		}

		if (preg_match_all(self::URL_BB_CODE_PATTERN, $sourceText, $matches) > 0)
		{
			foreach ($matches[0] as $urlTag)
			{
				if ($this->extractWholeUrlText((string)$urlTag, $sanitizeUrl) !== null)
				{
					return true;
				}
			}
		}

		return false;
	}

	public function extractWholeUrlText(string $sourceText, callable $sanitizeUrl): ?string
	{
		if (preg_match(self::WHOLE_URL_BB_CODE_PATTERN, $sourceText, $matches) !== 1)
		{
			return null;
		}

		$urlAttribute = (string)($matches[1] ?? '');
		$linkText = (string)($matches[2] ?? '');
		$linkUrl = $urlAttribute !== '' ? $urlAttribute : $linkText;
		if ($sanitizeUrl($linkUrl) === '')
		{
			return null;
		}

		$linkText = trim($linkText);

		return $linkText !== '' ? $linkText : $linkUrl;
	}

	public function format(
		string $escapedText,
		bool $preserveUrlTags,
		callable $sanitizeUrl,
		bool $preserveInlineTags = true,
	): string
	{
		$frames = [
			[
				'name' => null,
				'opening' => '',
				'url' => null,
				'content' => '',
			],
		];
		$length = strlen($escapedText);

		for ($offset = 0; $offset < $length;)
		{
			$openingOffset = strpos($escapedText, '[', $offset);
			if ($openingOffset === false)
			{
				$this->appendEscapedText($frames, substr($escapedText, $offset));

				break;
			}

			$this->appendEscapedText($frames, substr($escapedText, $offset, $openingOffset - $offset));
			$closingOffset = strpos($escapedText, ']', $openingOffset + 1);
			if ($closingOffset === false)
			{
				$this->appendEscapedText($frames, substr($escapedText, $openingOffset));

				break;
			}

			$token = substr($escapedText, $openingOffset, $closingOffset - $openingOffset + 1);
			$tag = $this->parseAllowedTag($token);
			if ($tag === null)
			{
				$this->appendEscapedText($frames, $token);
				$offset = $closingOffset + 1;

				continue;
			}

			if ($tag['closing'])
			{
				$lastFrameKey = array_key_last($frames);
				if ($lastFrameKey !== null && $frames[$lastFrameKey]['name'] === $tag['name'])
				{
					$frame = array_pop($frames);
					$renderedFrame = $this->renderAllowedFrame(
						$frame,
						$token,
						$preserveUrlTags,
						$preserveInlineTags,
						$sanitizeUrl,
					);
					$frames[array_key_last($frames)]['content'] .= $renderedFrame;
				}
				else
				{
					$this->appendEscapedText($frames, $token);
				}

				$offset = $closingOffset + 1;

				continue;
			}

			$frames[] = [
				'name' => $tag['name'],
				'opening' => $token,
				'url' => $tag['url'],
				'content' => '',
			];
			$offset = $closingOffset + 1;
		}

		while (count($frames) > 1)
		{
			$frame = array_pop($frames);
			$frames[array_key_last($frames)]['content']
				.= $this->escapeDelimiters($frame['opening']) . $frame['content'];
		}

		return $frames[0]['content'];
	}

	public function escapeDelimiters(string $text): string
	{
		$text = preg_replace('/&#(?:0*91|x0*5b);/i', '&amp;#91;', $text);
		$text = preg_replace('/&#(?:0*93|x0*5d);/i', '&amp;#93;', $text);

		return str_replace(['[', ']'], ['&amp;#91;', '&amp;#93;'], $text);
	}

	private function parseAllowedTag(string $token): ?array
	{
		$tagContent = substr($token, 1, -1);
		if ($tagContent === '')
		{
			return null;
		}

		if ($tagContent[0] === '/')
		{
			$name = strtoupper(substr($tagContent, 1));
			if ($this->isAllowedTagName($name))
			{
				return [
					'name' => $name,
					'closing' => true,
					'url' => null,
				];
			}

			return null;
		}

		$name = strtoupper($tagContent);
		if (isset(self::ALLOWED_INLINE_TAGS[$name]))
		{
			return [
				'name' => $name,
				'closing' => false,
				'url' => null,
			];
		}

		if ($name === self::URL_TAG || str_starts_with($name, self::URL_TAG . '='))
		{
			$url = null;
			if (str_starts_with($name, self::URL_TAG . '='))
			{
				$url = substr($tagContent, strlen(self::URL_TAG) + 1);
			}

			return [
				'name' => self::URL_TAG,
				'closing' => false,
				'url' => $url,
			];
		}

		return null;
	}

	private function isAllowedTagName(string $name): bool
	{
		return isset(self::ALLOWED_INLINE_TAGS[$name]) || $name === self::URL_TAG;
	}

	private function appendEscapedText(array &$frames, string $text): void
	{
		$frames[array_key_last($frames)]['content'] .= $this->escapeDelimiters($text);
	}

	private function renderAllowedFrame(
		array $frame,
		string $closingToken,
		bool $preserveUrlTags,
		bool $preserveInlineTags,
		callable $sanitizeUrl,
	): string
	{
		if ($frame['name'] !== self::URL_TAG)
		{
			if (!$preserveInlineTags)
			{
				return $frame['content'];
			}

			return $frame['opening'] . $frame['content'] . $closingToken;
		}

		if (!$preserveUrlTags)
		{
			return $frame['content'];
		}

		$safeUrl = $this->getSafeUrlForFrame($frame, $sanitizeUrl);
		if ($safeUrl === '')
		{
			return $frame['content'];
		}

		$safeUrl = htmlspecialchars($safeUrl, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8', false);

		return '[' . self::URL_TAG . '=' . $safeUrl . ']' . $frame['content'] . '[/' . self::URL_TAG . ']';
	}

	private function getSafeUrlForFrame(array $frame, callable $sanitizeUrl): string
	{
		$url = $frame['url'];
		if ($url === null || $url === '')
		{
			$url = $this->removeAllowedTags($frame['content']);
		}

		return $sanitizeUrl($url);
	}

	private function removeAllowedTags(string $text): string
	{
		return preg_replace('/\[\/?(?:B|I|U|S|URL)(?:=[^\]]*)?]/i', '', $text) ?? $text;
	}
}
