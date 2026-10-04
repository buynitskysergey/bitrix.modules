<?php

declare(strict_types=1);

namespace Bitrix\Crm\RepeatSale\DataCollector;

use Bitrix\AI\Tokenizer\TokenizerInterface;
use Bitrix\Crm\Feature;
use Bitrix\Crm\RepeatSale\DataCollector\Activity\ActivityType;
use Bitrix\Crm\RepeatSale\DataCollector\Activity\TextLengthConfig;
use Bitrix\Crm\RepeatSale\Transcription\TranscriptionBudget;

/**
 * Layer A of the transcript volume budget: an aggregate, deterministic trimmer applied to the
 * already-collected communication_data of a client. It enforces a single per-client token budget
 * over the call-recording transcripts across the base deal and the deals_list, so the AI prompt
 * cannot overflow regardless of how many transcripts were gathered.
 *
 * Only the CALL_RECORDING_TRANSCRIPTS channel is touched; other channels are left untouched. The
 * trimmer reads and trims the assembled structure in place - it does not re-select or re-normalize.
 */
class TranscriptBudgetTrimmer
{
	private const TRANSCRIPT_TYPE = ActivityType::CALL_RECORDING_TRANSCRIPTS;

	private int $minLengthChars;
	private TranscriptionBudget $budgetConfig;
	private ?TokenizerInterface $tokenizer;

	public function __construct(
		?TextLengthConfig $lengthConfig = null,
		?TranscriptionBudget $budgetConfig = null,
		?TokenizerInterface $tokenizer = null,
	)
	{
		$lengthConfig ??= new TextLengthConfig();
		$this->minLengthChars = (int)($lengthConfig->getConfigForType(self::TRANSCRIPT_TYPE)['min_length'] ?? 0);
		$this->budgetConfig = $budgetConfig ?? new TranscriptionBudget();
		$this->tokenizer = $tokenizer;
	}

	/**
	 * Flag-aware entry point used by the data collector managers: trims with the configured budget
	 * when the feature is enabled, otherwise leaves the structure untouched.
	 *
	 * @param array<int, array<string, mixed>> $commBlocks references to communication_data blocks,
	 *        base deal first, then deals_list by freshness
	 */
	public function trimResult(array &$commBlocks): void
	{
		$budgetTokens = $this->isFeatureEnabled() ? $this->budgetConfig->getContextBudgetTokens() : 0;

		$this->trim($commBlocks, $budgetTokens);
	}

	/**
	 * Aggregate trim (ALG-04). Transcript length is measured in tokens. Transcripts are kept whole
	 * while the budget lasts; the boundary transcript is truncated proportionally by characters to
	 * the remaining token budget (dropped if the truncated piece falls below the type's min length,
	 * translated to tokens by the boundary transcript's own chars-per-token ratio); all further
	 * transcripts across all blocks are dropped. A budget <= 0 is a no-op.
	 *
	 * @param array<int, array<string, mixed>> $commBlocks references to communication_data blocks in
	 *        priority order (base deal first)
	 */
	public function trim(array &$commBlocks, int $budgetTokens): void
	{
		if ($budgetTokens <= 0)
		{
			return;
		}

		$key = self::TRANSCRIPT_TYPE->value;
		$remaining = $budgetTokens;

		foreach ($commBlocks as &$block)
		{
			if (!is_array($block) || !isset($block[$key]) || !is_array($block[$key]))
			{
				continue;
			}

			$kept = [];
			foreach ($block[$key] as $transcript)
			{
				if ($remaining <= 0 || !is_string($transcript))
				{
					break;
				}

				$tokens = $this->getTokenizer()->count($transcript);
				if ($tokens <= $remaining)
				{
					$kept[] = $transcript;
					$remaining -= $tokens;

					continue;
				}

				$piece = $this->truncateToTokens($transcript, $remaining, $tokens);
				if ($piece !== null)
				{
					$kept[] = $piece;
				}
				$remaining = 0;

				break;
			}

			$block[$key] = $kept;
		}
		unset($block);
	}

	/**
	 * Truncates a transcript to fit the remaining token budget. Tokenizer-agnostic: makes a first cut
	 * by characters using the transcript's own average chars-per-token ratio, then measures the actual
	 * prefix and shrinks it until it truly fits the budget (the average ratio can understate the token
	 * density at the start, so a single proportional cut is not guaranteed to fit). The minimum length
	 * is enforced directly in characters.
	 */
	private function truncateToTokens(string $transcript, int $remainingTokens, int $transcriptTokens): ?string
	{
		if ($remainingTokens <= 0)
		{
			return null;
		}

		$charsPerToken = mb_strlen($transcript, 'UTF-8') / max(1, $transcriptTokens);
		$cutChars = (int)floor($remainingTokens * $charsPerToken);

		while ($cutChars > 0)
		{
			$piece = mb_substr($transcript, 0, $cutChars, 'UTF-8');
			$pieceTokens = $this->getTokenizer()->count($piece);
			if ($pieceTokens <= $remainingTokens)
			{
				return mb_strlen($piece, 'UTF-8') >= $this->minLengthChars ? $piece : null;
			}

			// shrink proportionally to the measured overshoot, always by at least one character so the
			// loop is guaranteed to make progress and terminate
			$next = (int)floor($cutChars * ($remainingTokens / max(1, $pieceTokens)));
			$cutChars = $next < $cutChars ? $next : $cutChars - 1;
		}

		return null;
	}

	protected function isFeatureEnabled(): bool
	{
		return Feature::enabled(Feature\RepeatSaleTranscription::class);
	}

	private function getTokenizer(): TokenizerInterface
	{
		return $this->tokenizer ??= $this->budgetConfig->getTokenizer();
	}
}
