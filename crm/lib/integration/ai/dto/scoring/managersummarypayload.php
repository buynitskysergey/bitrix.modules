<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\AI\Dto\Scoring;

use Bitrix\Crm\Dto\Caster;
use Bitrix\Crm\Dto\Dto;

final class ManagerSummaryPayload extends Dto
{
	/** @var array<int, array{scriptId: string, strengths: array<int, array{criterionId: string, comment: string}>, growthAreas: array<int, array{criterionId: string, comment: string}>}> */
	public array $perScript = [];
	/** @var array<int, array{scriptIds: string[], criterionIds: string[], comment: string}> */
	public array $crossScriptPatterns = [];
	/** @var array<int, array{priority: int, scriptIds: string[], criterionIds: string[], text: string, example: string}> */
	public array $recommendations = [];
	public string $summary = '';

	/**
	 * Data signature the summary was generated from (assessed-calls count for the period).
	 * Persisted alongside the payload in b_crm_ai_queue.RESULT so a later click can tell
	 * whether the underlying data changed and the cached summary may be re-delivered.
	 * Not part of the AI answer; ignored by ManagerSummaryFormatter.
	 */
	public int $sourceSignature = 0;

	/**
	 * Unix bounds of the data window [D-7d, D] this summary describes, pinned at launch to the
	 * reference date the summary button was shown (the asynchronous finish has no reference date).
	 * Seeded into b_crm_ai_queue.RESULT at launch and read back in extractPayloadFromAIResult().
	 * Null for legacy/empty launch results. Not part of the AI answer; rendered via the header.
	 */
	public ?int $periodFrom = null;
	public ?int $periodTo = null;

	public function getCastByPropertyName(string $propertyName): ?Caster
	{
		return match ($propertyName)
		{
			'perScript', 'crossScriptPatterns', 'recommendations' => new Caster\RawArrayCaster(),
			default => null,
		};
	}
}
