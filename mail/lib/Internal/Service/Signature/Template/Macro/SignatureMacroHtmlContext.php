<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Signature\Template\Macro;

final class SignatureMacroHtmlContext
{
	private const RAW_TEXT_ELEMENTS = ['script', 'style', 'textarea', 'title'];

	/** @param string[] $tokens */
	public function findFirstTokenOutsideText(string $html, array $tokens): ?string
	{
		$unsafeRanges = $this->findUnsafeRanges($html);
		$firstToken = null;
		$firstOffset = null;
		foreach ($tokens as $token)
		{
			$offset = 0;
			while (($offset = strpos($html, $token, $offset)) !== false)
			{
				if ($this->isInsideRanges($offset, $unsafeRanges) && ($firstOffset === null || $offset < $firstOffset))
				{
					$firstToken = $token;
					$firstOffset = $offset;
				}

				$offset += strlen($token);
			}
		}

		return $firstToken;
	}

	/** @return array<int, array{0: int, 1: int}> */
	private function findUnsafeRanges(string $html): array
	{
		$ranges = [];
		$length = strlen($html);
		$offset = 0;
		while ($offset < $length)
		{
			$tagStart = strpos($html, '<', $offset);
			if ($tagStart === false)
			{
				break;
			}

			if (substr($html, $tagStart, 4) === '<!--')
			{
				$commentEnd = strpos($html, '-->', $tagStart + 4);
				$end = $commentEnd === false ? $length : $commentEnd + 3;
				$ranges[] = [$tagStart, $end];
				$offset = $end;

				continue;
			}
			if (!$this->isMarkupStart($html, $tagStart))
			{
				$offset = $tagStart + 1;

				continue;
			}

			$tagEnd = $this->findTagEnd($html, $tagStart + 1);
			if ($tagEnd === null)
			{
				$ranges[] = [$tagStart, $length];

				break;
			}

			$ranges[] = [$tagStart, $tagEnd + 1];
			$tag = substr($html, $tagStart, $tagEnd + 1 - $tagStart);
			if (preg_match('~^<\s*([a-z][a-z0-9:-]*)\b~i', $tag, $matches) === 1)
			{
				$name = strtolower($matches[1]);
				if (in_array($name, self::RAW_TEXT_ELEMENTS, true) && !str_ends_with(rtrim($tag), '/>'))
				{
					$closingStart = $this->findClosingTagStart($html, $name, $tagEnd + 1);
					if ($closingStart === null)
					{
						$ranges[] = [$tagEnd + 1, $length];

						break;
					}

					$ranges[] = [$tagEnd + 1, $closingStart];
					$offset = $closingStart;

					continue;
				}
			}

			$offset = $tagEnd + 1;
		}

		return $ranges;
	}

	private function isMarkupStart(string $html, int $offset): bool
	{
		$length = strlen($html);
		$offset++;
		if ($offset >= $length)
		{
			return false;
		}

		$character = $html[$offset];
		if ($character === '!' || $character === '?')
		{
			return true;
		}
		if ($character !== '/')
		{
			return ctype_alpha($character);
		}

		for ($offset++; $offset < $length && ctype_space($html[$offset]); $offset++)
		{
		}

		return $offset < $length && ctype_alpha($html[$offset]);
	}

	private function findClosingTagStart(string $html, string $name, int $offset): ?int
	{
		$found = preg_match(
			'~</\s*' . preg_quote($name, '~') . '\b~i',
			$html,
			$matches,
			PREG_OFFSET_CAPTURE,
			$offset,
		);

		return $found === 1 ? $matches[0][1] : null;
	}

	private function findTagEnd(string $html, int $offset): ?int
	{
		$quote = null;
		$length = strlen($html);
		for (; $offset < $length; $offset++)
		{
			$character = $html[$offset];
			if ($quote !== null)
			{
				if ($character === $quote)
				{
					$quote = null;
				}

				continue;
			}

			if ($character === '"' || $character === "'")
			{
				$quote = $character;
			}
			elseif ($character === '>')
			{
				return $offset;
			}
		}

		return null;
	}

	/** @param array<int, array{0: int, 1: int}> $ranges */
	private function isInsideRanges(int $offset, array $ranges): bool
	{
		$left = 0;
		$right = count($ranges) - 1;
		while ($left <= $right)
		{
			$middle = intdiv($left + $right, 2);
			[$start, $end] = $ranges[$middle];
			if ($offset < $start)
			{
				$right = $middle - 1;
			}
			elseif ($offset >= $end)
			{
				$left = $middle + 1;
			}
			else
			{
				return true;
			}
		}

		return false;
	}
}
