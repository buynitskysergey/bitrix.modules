<?php

declare(strict_types=1);

namespace Bitrix\Crm\RepeatSale\Transcription;

use Bitrix\AI\Tokenizer\GPT;
use Bitrix\AI\Tokenizer\TokenizerInterface;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;

/**
 * Per-client transcript volume budget (tokens) shared by the two guard layers: the aggregate
 * trimmer at data collection (layer A) and the early-stop selection in the gate (layer B). It also
 * is the single provider of the tokenizer used to measure transcript volume.
 *
 * A budget of 0 or less disables the budget (only the per-item cap and the count limit remain).
 */
final class TranscriptionBudget
{
	public const CONTEXT_BUDGET_OPTION = 'repeat_sale_transcription_context_budget';
	public const DEFAULT_CONTEXT_BUDGET = 40000;

	public const TOKENS_PER_SECOND_OPTION = 'repeat_sale_transcription_tokens_per_second';
	public const DEFAULT_TOKENS_PER_SECOND = 6;

	public function getContextBudgetTokens(): int
	{
		// a non-positive value is a valid "budget disabled" setting - do not clamp to the default
		return (int)Option::get('crm', self::CONTEXT_BUDGET_OPTION, self::DEFAULT_CONTEXT_BUDGET);
	}

	public function getTokensPerSecond(): int
	{
		$value = (int)Option::get('crm', self::TOKENS_PER_SECOND_OPTION, self::DEFAULT_TOKENS_PER_SECOND);

		return $value > 0 ? $value : self::DEFAULT_TOKENS_PER_SECOND;
	}

	public function getTokenizer(): TokenizerInterface
	{
		// ai module is a hard requirement here; real pipeline paths are already gated by AI
		// availability, and the data-collector managers catch Throwable and log
		Loader::requireModule('ai');

		return new GPT();
	}
}
