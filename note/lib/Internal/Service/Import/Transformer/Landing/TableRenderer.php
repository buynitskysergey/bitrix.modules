<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Import\Transformer\Landing;

/**
 * Renders a markup `<table>` node as a GFM markdown table.
 *
 * Shared by {@see HtmlToMarkdown} (a table in any block) and the landing table converter, so a
 * table survives wherever the designer put it. The renderer knows nothing about landing blocks:
 * it walks a markup node and asks the caller to render each cell's body.
 *
 * A markdown table expresses neither merged cells nor block content inside a cell, so the
 * unsupported parts degrade in a fixed way:
 * - a cell's block structure (paragraphs, lists, a soft line break) collapses to one line - the
 *   cell markdown is joined with spaces and its pipes escaped;
 * - `colspan` keeps the text in its first column and pads the merged ones with empty cells, so the
 *   columns after the merge stay aligned;
 * - `rowspan` keeps the text in its first row and leaves an empty cell in the rows it covered;
 * - a table nested in another table (or in a list item) has no markdown form at all - the caller
 *   asks for {@see flatten()} instead, which degrades it to the text of its cells.
 *
 * Landing editor service cells (select-all, row and column drag handles) are dropped before
 * rendering: they carry no page content and would add an empty leading column. A row left without
 * any content cell disappears with them, so the header of the delivery's table blocks becomes the
 * first row that actually holds cells - never a row of data.
 */
final class TableRenderer
{
	private const SERVICE_CELL_CLASSES = [
		'landing-table-th-select-all',
		'landing-table-col-dnd',
		'landing-table-row-dnd',
	];
	/** Bounds a hostile or broken `colspan`/`rowspan` so one cell cannot inflate the whole grid. */
	private const MAX_COLUMNS = 32;

	/**
	 * @param \Closure(\DOMElement): string $renderCell cell body -> markdown
	 */
	public static function render(\DOMElement $table, \Closure $renderCell): string
	{
		$grid = self::grid($table, $renderCell);
		if ($grid === [])
		{
			return '';
		}

		$width = count($grid[0]);
		$header = array_shift($grid);

		$lines = [
			'| ' . implode(' | ', $header) . ' |',
			'|' . str_repeat(' --- |', $width),
		];
		foreach ($grid as $row)
		{
			$lines[] = '| ' . implode(' | ', $row) . ' |';
		}

		return implode("\n", $lines);
	}

	/**
	 * Cell texts of the table on a single line - the degradation for a table markdown cannot nest
	 * (inside another table's cell, inside a list item).
	 *
	 * @param \Closure(\DOMElement): string $renderCell cell body -> markdown
	 */
	public static function flatten(\DOMElement $table, \Closure $renderCell): string
	{
		$parts = [];
		foreach (self::rows($table) as $row)
		{
			foreach (self::contentCells($row) as $cell)
			{
				$text = self::flattenCell($renderCell($cell));
				if ($text !== '')
				{
					$parts[] = $text;
				}
			}
		}

		return implode(' ', $parts);
	}

	/**
	 * Collapses markdown onto the single line a table cell has: the pipe is escaped so it cannot
	 * open a cell of its own, and block structure (paragraphs, a list, a soft line break) folds
	 * into that line. The one rule for a cell, wherever the cell markdown comes from - this
	 * renderer's own cells or {@see HtmlToMarkdown::convertCell()}.
	 *
	 * Idempotent: an already-escaped pipe is normalized back first, so flattening a cell that was
	 * built out of flattened parts does not double the escape.
	 */
	public static function flattenCell(string $markdown): string
	{
		$markdown = str_replace('\\|', '|', $markdown);
		$markdown = str_replace('|', '\\|', $markdown);
		$markdown = preg_replace('/\s*\R\s*/u', ' ', $markdown) ?? $markdown;
		$markdown = preg_replace('/ {2,}/', ' ', $markdown) ?? $markdown;

		return trim($markdown);
	}

	/**
	 * @param \Closure(\DOMElement): string $renderCell
	 * @return array<int, array<int, string>> rows of equal width, empty when the table holds no cells
	 */
	private static function grid(\DOMElement $table, \Closure $renderCell): array
	{
		$rows = [];
		/** @var array<int, int> $covered column index -> rows still covered by a rowspan */
		$covered = [];
		foreach (self::rows($table) as $row)
		{
			$cells = self::contentCells($row);
			if ($cells === [])
			{
				continue;
			}

			$line = [];
			foreach ($covered as $index => $left)
			{
				$line[$index] = '';
				if ($left > 1)
				{
					$covered[$index] = $left - 1;
				}
				else
				{
					unset($covered[$index]);
				}
			}

			$column = 0;
			foreach ($cells as $cell)
			{
				while (isset($line[$column]))
				{
					$column++;
				}
				if ($column >= self::MAX_COLUMNS)
				{
					break;
				}

				$line[$column] = self::flattenCell($renderCell($cell));
				$colspan = self::span($cell, 'colspan');
				$rowspan = self::span($cell, 'rowspan');
				for ($offset = 0; $offset < $colspan; $offset++)
				{
					if ($offset > 0)
					{
						$line[$column + $offset] = '';
					}
					if ($rowspan > 1)
					{
						$covered[$column + $offset] = $rowspan - 1;
					}
				}
				$column += $colspan;
			}

			ksort($line);
			$rows[] = $line;
		}

		return self::alignWidth($rows);
	}

	/**
	 * Pads every row to the widest one and closes the gaps a `colspan` left behind, so all rows
	 * carry the same dense list of cells.
	 *
	 * @param array<int, array<int, string>> $rows
	 * @return array<int, array<int, string>>
	 */
	private static function alignWidth(array $rows): array
	{
		$width = 0;
		foreach ($rows as $row)
		{
			$width = max($width, $row === [] ? 0 : (int)array_key_last($row) + 1);
		}
		if ($width === 0)
		{
			return [];
		}

		$aligned = [];
		foreach ($rows as $row)
		{
			$dense = [];
			for ($column = 0; $column < $width; $column++)
			{
				$dense[] = $row[$column] ?? '';
			}
			$aligned[] = $dense;
		}

		return $aligned;
	}

	/**
	 * Rows of this table only: the rows of a nested table belong to that table, so they must not be
	 * pulled in by a `getElementsByTagName()` style descendant walk.
	 *
	 * @return \DOMElement[]
	 */
	private static function rows(\DOMElement $table): array
	{
		$rows = [];
		foreach ($table->childNodes as $child)
		{
			if (!$child instanceof \DOMElement)
			{
				continue;
			}

			$tag = strtolower($child->nodeName);
			if ($tag === 'tr')
			{
				$rows[] = $child;
			}
			elseif (in_array($tag, ['thead', 'tbody', 'tfoot'], true))
			{
				foreach ($child->childNodes as $row)
				{
					if ($row instanceof \DOMElement && strtolower($row->nodeName) === 'tr')
					{
						$rows[] = $row;
					}
				}
			}
		}

		return $rows;
	}

	/**
	 * @return \DOMElement[] the row's own `td`/`th` cells, editor service cells excluded
	 */
	private static function contentCells(\DOMElement $row): array
	{
		$cells = [];
		foreach ($row->childNodes as $cell)
		{
			if (
				$cell instanceof \DOMElement
				&& in_array(strtolower($cell->nodeName), ['td', 'th'], true)
				&& !self::isServiceCell($cell)
			)
			{
				$cells[] = $cell;
			}
		}

		return $cells;
	}

	private static function isServiceCell(\DOMElement $cell): bool
	{
		$classes = preg_split('/\s+/', trim($cell->getAttribute('class'))) ?: [];
		foreach (self::SERVICE_CELL_CLASSES as $service)
		{
			if (in_array($service, $classes, true))
			{
				return true;
			}
		}

		return false;
	}

	private static function span(\DOMElement $cell, string $attribute): int
	{
		$span = (int)$cell->getAttribute($attribute);

		return max(1, min($span, self::MAX_COLUMNS));
	}
}
