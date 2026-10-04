<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\CallAssessment\Summary;

use Bitrix\Crm\Integration\AI\Dto\Scoring\ManagerSummaryPayload;
use Bitrix\Main\Context;
use Bitrix\Main\Localization\Loc;

/**
 * Renders a parsed manager-summary AI payload (DTO-02) into a human readable
 * im chat message. Uses im BB-code ([b], line breaks); never emits raw JSON.
 * All external data (manager name, script titles, period bounds) arrives via
 * ManagerSummaryRenderContext so this class stays free of DB/Container access.
 */
final class ManagerSummaryFormatter
{
	private const FALLBACK_DAY_MONTH_FORMAT = 'j F';
	private const FALLBACK_LONG_DATE_FORMAT = 'j F Y';

	public function format(ManagerSummaryPayload $payload, ManagerSummaryRenderContext $context): string
	{
		$summary = $this->neutralizeBbCode(trim($payload->summary));

		$header = '[b]' . $this->buildHeader($context) . '[/b]';
		if ($summary !== '')
		{
			$header .= "\n" . $summary;
		}

		$blocks = [$header];

		$perScript = $this->formatPerScript($payload->perScript, $context->scriptNames, $context->criterionNames);
		if ($perScript !== '')
		{
			$blocks[] = $perScript;
		}

		$cross = $this->formatCrossScriptPatterns(
			$payload->crossScriptPatterns,
			$context->scriptNames,
			$context->criterionNames,
		);
		if ($cross !== '')
		{
			$blocks[] = $cross;
		}

		$recommendations = $this->formatRecommendations(
			$payload->recommendations,
			$context->scriptNames,
			$context->criterionNames,
		);
		if ($recommendations !== '')
		{
			$blocks[] = $recommendations;
		}

		if (count($blocks) === 1 && $summary === '')
		{
			return (string)Loc::getMessage('CRM_MANAGER_SUMMARY_FORMATTER_EMPTY');
		}

		return implode("\n\n", $blocks);
	}

	/**
	 * @param array<int, string> $scriptNames script assessment-setting id => title
	 * @param array<int, string> $criterionNames criterion definition id => title
	 */
	private function formatPerScript(array $rows, array $scriptNames, array $criterionNames): string
	{
		$scriptBlocks = [];
		foreach ($rows as $row)
		{
			if (!is_array($row))
			{
				continue;
			}

			$strengths = $this->formatComments($row['strengths'] ?? [], $criterionNames);
			$growthAreas = $this->formatComments($row['growthAreas'] ?? [], $criterionNames);
			if ($strengths === [] && $growthAreas === [])
			{
				continue;
			}

			$scriptId = (int)($row['scriptId'] ?? 0);
			$scriptName = $this->neutralizeBbCode(trim((string)($scriptNames[$scriptId] ?? '')));
			$title = $scriptName !== ''
				? (string)Loc::getMessage('CRM_MANAGER_SUMMARY_FORMATTER_SCRIPT', ['#SCRIPT#' => $scriptName])
				: (string)Loc::getMessage('CRM_MANAGER_SUMMARY_FORMATTER_SCRIPT_GENERIC')
			;

			$lines = ['[b]' . $title . '[/b]'];
			if ($strengths !== [])
			{
				$lines[] = (string)Loc::getMessage('CRM_MANAGER_SUMMARY_FORMATTER_STRENGTHS');
				array_push($lines, ...$strengths);
			}
			if ($growthAreas !== [])
			{
				$lines[] = (string)Loc::getMessage('CRM_MANAGER_SUMMARY_FORMATTER_GROWTH');
				array_push($lines, ...$growthAreas);
			}

			$scriptBlocks[] = implode("\n", $lines);
		}

		if ($scriptBlocks === [])
		{
			return '';
		}

		return '[b]' . Loc::getMessage('CRM_MANAGER_SUMMARY_FORMATTER_PER_SCRIPT') . '[/b]'
			. "\n" . implode("\n", $scriptBlocks)
		;
	}

	/**
	 * @param array<int, string> $scriptNames script assessment-setting id => title
	 * @param array<int, string> $criterionNames criterion definition id => title
	 */
	private function formatCrossScriptPatterns(array $rows, array $scriptNames, array $criterionNames): string
	{
		$lines = [];
		foreach ($rows as $row)
		{
			if (!is_array($row))
			{
				continue;
			}

			$comment = trim((string)($row['comment'] ?? ''));
			if ($comment === '')
			{
				continue;
			}

			$lines[] = '- ' . $this->neutralizeBbCode($comment);

			$scope = $this->formatScope(
				$row['scriptIds'] ?? [],
				$row['criterionIds'] ?? [],
				$scriptNames,
				$criterionNames,
			);
			if ($scope !== '')
			{
				$lines[] = $scope;
			}
		}

		if ($lines === [])
		{
			return '';
		}

		return '[b]' . Loc::getMessage('CRM_MANAGER_SUMMARY_FORMATTER_CROSS') . '[/b]'
			. "\n" . implode("\n", $lines)
		;
	}

	/**
	 * @param array<int, string> $scriptNames script assessment-setting id => title
	 * @param array<int, string> $criterionNames criterion definition id => title
	 */
	private function formatRecommendations(array $rows, array $scriptNames, array $criterionNames): string
	{
		$items = [];
		foreach ($rows as $row)
		{
			if (!is_array($row))
			{
				continue;
			}

			$text = trim((string)($row['text'] ?? ''));
			if ($text === '')
			{
				continue;
			}

			$items[] = [
				'priority' => (int)($row['priority'] ?? 0),
				'text' => $this->neutralizeBbCode($text),
				'scope' => $this->formatScope(
					$row['scriptIds'] ?? [],
					$row['criterionIds'] ?? [],
					$scriptNames,
					$criterionNames,
				),
				'example' => $this->neutralizeBbCode(trim((string)($row['example'] ?? ''))),
			];
		}

		if ($items === [])
		{
			return '';
		}

		// Priority 1 is the most important; a missing/zero priority sinks to the bottom.
		usort($items, static function (array $a, array $b): int {
			$pa = $a['priority'] > 0 ? $a['priority'] : PHP_INT_MAX;
			$pb = $b['priority'] > 0 ? $b['priority'] : PHP_INT_MAX;

			return $pa <=> $pb;
		});

		$lines = ['[b]' . Loc::getMessage('CRM_MANAGER_SUMMARY_FORMATTER_RECOMMENDATIONS') . '[/b]'];
		$index = 1;
		foreach ($items as $item)
		{
			$lines[] = $index . '. ' . $item['text'];
			if ($item['scope'] !== '')
			{
				$lines[] = $item['scope'];
			}
			if ($item['example'] !== '')
			{
				$lines[] = (string)Loc::getMessage(
					'CRM_MANAGER_SUMMARY_FORMATTER_EXAMPLE',
					['#EXAMPLE#' => $item['example']],
				);
			}
			$index++;
		}

		return implode("\n", $lines);
	}

	/**
	 * Builds bullet lines from {criterionId, comment} rows. When the criterion id resolves to a
	 * known title the line becomes "- <criterion>: <comment>", otherwise it degrades to "- <comment>".
	 * Empty comments are skipped.
	 *
	 * @param array<int, string> $criterionNames criterion definition id => title
	 * @return string[]
	 */
	private function formatComments(mixed $rows, array $criterionNames): array
	{
		if (!is_array($rows))
		{
			return [];
		}

		$result = [];
		foreach ($rows as $row)
		{
			if (!is_array($row))
			{
				continue;
			}

			$comment = trim((string)($row['comment'] ?? ''));
			if ($comment === '')
			{
				continue;
			}

			$comment = $this->neutralizeBbCode($comment);
			$criterion = $this->resolveNames([$row['criterionId'] ?? ''], $criterionNames);
			$result[] = $criterion !== ''
				? (string)Loc::getMessage(
					'CRM_MANAGER_SUMMARY_FORMATTER_COMMENT',
					['#CRITERION#' => $criterion, '#COMMENT#' => $comment],
				)
				: '- ' . $comment
			;
		}

		return $result;
	}

	/**
	 * Builds the "relates to: scripts — ...; criteria — ..." line for a recommendation or a
	 * cross-script pattern. Only ids resolvable via the name maps are shown; raw ids never leak
	 * and each part is omitted when nothing resolves. Returns '' when neither side has names.
	 *
	 * @param array<int, string> $scriptNames script assessment-setting id => title
	 * @param array<int, string> $criterionNames criterion definition id => title
	 */
	private function formatScope(mixed $scriptIds, mixed $criterionIds, array $scriptNames, array $criterionNames): string
	{
		$scripts = $this->resolveNames(is_array($scriptIds) ? $scriptIds : [], $scriptNames);
		$criteria = $this->resolveNames(is_array($criterionIds) ? $criterionIds : [], $criterionNames);

		if ($scripts !== '' && $criteria !== '')
		{
			return (string)Loc::getMessage(
				'CRM_MANAGER_SUMMARY_FORMATTER_SCOPE',
				['#SCRIPTS#' => $scripts, '#CRITERIA#' => $criteria],
			);
		}
		if ($scripts !== '')
		{
			return (string)Loc::getMessage('CRM_MANAGER_SUMMARY_FORMATTER_SCOPE_SCRIPTS', ['#SCRIPTS#' => $scripts]);
		}
		if ($criteria !== '')
		{
			return (string)Loc::getMessage('CRM_MANAGER_SUMMARY_FORMATTER_SCOPE_CRITERIA', ['#CRITERIA#' => $criteria]);
		}

		return '';
	}

	/**
	 * Maps a list of ids to their titles via the given map, dropping ids with no known title, and
	 * joins the neutralized titles with a comma. Titles come from the DB (trusted) but still pass
	 * through neutralizeBbCode, like script names. Returns '' when nothing resolves.
	 *
	 * @param array<int, string> $nameMap id => title
	 */
	private function resolveNames(mixed $ids, array $nameMap): string
	{
		if (!is_array($ids))
		{
			return '';
		}

		$names = [];
		foreach ($ids as $id)
		{
			$name = trim((string)($nameMap[(int)$id] ?? ''));
			if ($name !== '')
			{
				$names[] = $this->neutralizeBbCode($name);
			}
		}

		return implode(', ', $names);
	}

	private function buildHeader(ManagerSummaryRenderContext $context): string
	{
		$manager = $this->formatManagerMention($context);
		$period = $this->formatPeriod($context->periodFrom, $context->periodTo);

		if ($manager !== '')
		{
			return $period !== ''
				? (string)Loc::getMessage(
					'CRM_MANAGER_SUMMARY_FORMATTER_HEADER_MANAGER_PERIOD',
					['#MANAGER#' => $manager, '#PERIOD#' => $period],
				)
				: (string)Loc::getMessage(
					'CRM_MANAGER_SUMMARY_FORMATTER_HEADER_MANAGER',
					['#MANAGER#' => $manager],
				)
			;
		}

		return $period !== ''
			? (string)Loc::getMessage('CRM_MANAGER_SUMMARY_FORMATTER_HEADER_PERIOD', ['#PERIOD#' => $period])
			: (string)Loc::getMessage('CRM_MANAGER_SUMMARY_FORMATTER_HEADER')
		;
	}

	/**
	 * Builds a clickable im mention of the manager. The display name is untrusted, so its BBCode
	 * brackets are neutralized before it goes inside the trusted [USER=id]...[/USER] markup (the
	 * im client HTML-escapes < > & itself). Empty name -> no mention, header degrades to generic.
	 */
	private function formatManagerMention(ManagerSummaryRenderContext $context): string
	{
		if ($context->managerId <= 0 || $context->managerName === '')
		{
			return '';
		}

		return '[USER=' . $context->managerId . ']'
			. $this->neutralizeBbCode($context->managerName)
			. '[/USER]'
		;
	}

	/**
	 * Formats the data window as a culture-aware "day month" range (year added only when the
	 * bounds fall in different years). Returns '' when either bound is missing.
	 */
	private function formatPeriod(?int $from, ?int $to): string
	{
		if ($from === null || $to === null)
		{
			return '';
		}

		$culture = Context::getCurrent()?->getCulture();

		$sameYear = date('Y', $from) === date('Y', $to);
		$format = $sameYear
			? ($culture?->getDayMonthFormat() ?: self::FALLBACK_DAY_MONTH_FORMAT)
			: ($culture?->getLongDateFormat() ?: self::FALLBACK_LONG_DATE_FORMAT)
		;

		return \FormatDate($format, $from) . ' – ' . \FormatDate($format, $to);
	}

	/**
	 * Neutralizes BBCode brackets in untrusted (AI/manager-derived) fragments so they
	 * cannot form active tags in the bot message. The im client HTML-escapes < > & ' "
	 * before parsing BBCode but leaves [ ] intact, and bot messages skip filterUserBbCodes;
	 * fullwidth brackets render as look-alike glyphs yet never match the ASCII-[ tag parser.
	 */
	private function neutralizeBbCode(string $value): string
	{
		return strtr($value, ['[' => "\u{FF3B}", ']' => "\u{FF3D}"]);
	}
}
