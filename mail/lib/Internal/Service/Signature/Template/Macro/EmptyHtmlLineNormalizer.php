<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Signature\Template\Macro;

final class EmptyHtmlLineNormalizer
{
	private const MAX_MARKUP_ITEMS_TO_NORMALIZE = 200;
	private const CONTAINER_PATTERN = '~</?(?:p|div)\b[^>]*>~i';
	private const BREAK_PATTERN = '~<br\b[^>]*>~i';
	private const MARKUP_PATTERN = '~</?(?:p|div)\b[^>]*>|<br\b[^>]*>~i';
	private const COMMENT_PATTERN = '~<!--.*?-->~s';
	private const VISIBLE_ELEMENT_PATTERN = '~<(?:img|table|audio|video|canvas|svg|iframe|object|embed|input|hr)\b~i';

	/** @param array<string, string> $replacements */
	public function normalize(string $template, array $replacements): string
	{
		if (!$this->containsResolvedToken($template, $replacements))
		{
			return $template;
		}
		if (preg_match_all(self::MARKUP_PATTERN, $template) > self::MAX_MARKUP_ITEMS_TO_NORMALIZE)
		{
			return strtr($template, $replacements);
		}

		$template = $this->removeEmptyContainers($template, $replacements);
		$template = $this->removeEmptyBreakSegments($template, $replacements);

		return strtr($template, $replacements);
	}

	/** @param array<string, string> $replacements */
	private function removeEmptyContainers(string $template, array $replacements): string
	{
		preg_match_all(self::CONTAINER_PATTERN, $template, $matches, PREG_OFFSET_CAPTURE);
		$stack = [];
		$ranges = [];
		foreach ($matches[0] as [$tag, $offset])
		{
			$isClosing = str_starts_with($tag, '</');
			preg_match('~^</?([a-z]+)~i', $tag, $tagNameMatch);
			$tagName = strtolower($tagNameMatch[1] ?? '');
			if (!$isClosing)
			{
				$stack[] = [
					'tag' => $tagName,
					'start' => $offset,
					'innerStart' => $offset + strlen($tag),
				];

				continue;
			}

			$opening = array_pop($stack);
			if ($opening === null || $opening['tag'] !== $tagName)
			{
				$stack = [];

				continue;
			}

			$inner = substr($template, $opening['innerStart'], $offset - $opening['innerStart']);
			if (
				$this->containsResolvedToken($inner, $replacements)
				&& !$this->isVisible(strtr($inner, $replacements))
			)
			{
				$ranges[] = [$opening['start'], $offset + strlen($tag)];
			}
		}

		return $this->removeRanges($template, $ranges);
	}

	/** @param array<string, string> $replacements */
	private function removeEmptyBreakSegments(string $template, array $replacements): string
	{
		preg_match_all(self::BREAK_PATTERN, $template, $matches, PREG_OFFSET_CAPTURE);
		$breaks = $matches[0];
		if ($breaks === [])
		{
			return $template;
		}

		$boundaries = [[null, 0]];
		foreach ($breaks as [$break, $offset])
		{
			$boundaries[] = [[$offset, $offset + strlen($break)], $offset + strlen($break)];
		}
		$boundaries[] = [[strlen($template), strlen($template)], strlen($template)];

		$ranges = [];
		for ($index = 0, $count = count($boundaries) - 1; $index < $count; $index++)
		{
			$segmentStart = $boundaries[$index][1];
			$segmentEnd = $boundaries[$index + 1][0][0];
			$segment = substr($template, $segmentStart, $segmentEnd - $segmentStart);
			if (
				!$this->containsResolvedToken($segment, $replacements)
				|| $this->isVisible(strtr($segment, $replacements))
				|| $this->containsStructuralMarkup($segment)
			)
			{
				continue;
			}

			if ($index + 1 < $count)
			{
				$ranges[] = [$segmentStart, $boundaries[$index + 1][0][1]];
			}
			elseif ($index > 0)
			{
				$rangeStart = $boundaries[$index][0][0];
				$previousRange = $ranges[array_key_last($ranges)] ?? null;
				if ($previousRange !== null && $previousRange[1] >= $boundaries[$index][0][1])
				{
					$rangeStart = $boundaries[$index - 1][0][0] ?? 0;
				}

				$ranges[] = [$rangeStart, $segmentEnd];
			}
		}

		return $this->removeRanges($template, $ranges);
	}

	private function isVisible(string $html): bool
	{
		if (preg_match(self::VISIBLE_ELEMENT_PATTERN, $html) === 1)
		{
			return true;
		}

		$html = preg_replace(self::COMMENT_PATTERN, '', $html) ?? $html;
		$html = preg_replace(self::BREAK_PATTERN, '', $html) ?? $html;
		$text = strip_tags($html);
		$text = preg_replace('~(?:&nbsp;|&#0*160;|&#x0*a0;|\x{00a0}|\s)+~iu', '', $text) ?? $text;

		return $text !== '';
	}

	private function containsStructuralMarkup(string $html): bool
	{
		return preg_match('~</?(?:table|thead|tbody|tfoot|tr|td|th|ul|ol|li|p|div)\b~i', $html) === 1;
	}

	/** @param array<string, string> $replacements */
	private function containsResolvedToken(string $html, array $replacements): bool
	{
		foreach ($replacements as $token => $value)
		{
			if (str_contains($html, $token))
			{
				return true;
			}
		}

		return false;
	}

	/** @param array<int, array{0: int, 1: int}> $ranges */
	private function removeRanges(string $html, array $ranges): string
	{
		usort(
			$ranges,
			static fn(array $left, array $right): int => $left[0] <=> $right[0] ?: $right[1] <=> $left[1],
		);
		$mergedRanges = [];
		foreach ($ranges as [$start, $end])
		{
			$lastIndex = count($mergedRanges) - 1;
			if ($lastIndex >= 0 && $start <= $mergedRanges[$lastIndex][1])
			{
				$mergedRanges[$lastIndex][1] = max($mergedRanges[$lastIndex][1], $end);

				continue;
			}

			$mergedRanges[] = [$start, $end];
		}

		foreach (array_reverse($mergedRanges) as [$start, $end])
		{
			$html = substr_replace($html, '', $start, $end - $start);
		}

		return $html;
	}
}
