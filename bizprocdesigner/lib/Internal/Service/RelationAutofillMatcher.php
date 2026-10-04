<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Service;

use Bitrix\Bizproc\Public\Activity\Interface\RelationFieldResolver;
use Bitrix\Bizproc\Public\Activity\Registry\RelationFieldResolverRegistry;

/**
 * Design-time matcher that builds the autofill package for a "Create" sub-action of a target node:
 * a map of target form field code -> bizproc expression {=<sourceBlockId>:<documentOutput>.<sourcePropertyId>}.
 *
 * It never transforms values and never computes anything: it only mirrors direct relation rules
 * provided by the target module's {@see RelationFieldResolver}. A field is filled only when exactly
 * one ancestor unambiguously feeds it; a missing resolver, a target the resolver does not support,
 * no match or an ambiguous match leaves the field empty (fail-open) so the frontend fills it manually.
 *
 * The service is created per request; the relation map is cached in-instance per (source, target)
 * document-type pair so repeated ancestors of the same type never hit the resolver twice.
 */
final class RelationAutofillMatcher
{
	/** @var array<string, array<string, array{sourcePropertyId: string, required: bool}>> */
	private array $relationCache = [];

	public function __construct(
		private readonly RelationFieldResolverRegistry $registry,
	)
	{
	}

	/**
	 * @param list<array{blockId: string, documentType: array, outputs: list<string>, documentOutput: string}> $sourceCandidates
	 *   Relation ancestors. `outputs` are the normalized output codes the ancestor exposes for reference;
	 *   `documentOutput` is the code of the ancestor's document output through which the linked entity id
	 *   is reached (accessor `documentOutput.propertyId`).
	 * @param array $targetDocumentType Standard Bitrix triplet [moduleId, entity, documentType] of the
	 *   target ("Create") node.
	 *
	 * @return array<string, string> Autofill map: field code => "{=<blockId>:<documentOutput>.<propertyId>}".
	 *   Empty when there is no resolver or no unambiguous match.
	 */
	public function buildAutofillMap(array $sourceCandidates, array $targetDocumentType): array
	{
		$resolver = $this->registry->resolve($targetDocumentType);
		if ($resolver === null || !$resolver->supports($targetDocumentType))
		{
			// Early applicability gate: the resolver decides whether it handles this target at all.
			return [];
		}

		/** @var array<string, list<string>> $targetFields fieldCode => list of "{=blockId:propertyId}" */
		$targetFields = [];
		foreach ($sourceCandidates as $candidate)
		{
			$blockId = (string)($candidate['blockId'] ?? '');
			$sourceDocumentType = $candidate['documentType'] ?? null;
			$outputs = $candidate['outputs'] ?? [];
			$documentOutput = (string)($candidate['documentOutput'] ?? '');
			if ($blockId === '' || !is_array($sourceDocumentType) || !is_array($outputs))
			{
				continue;
			}

			if ($documentOutput === '' || !in_array($documentOutput, $outputs, true))
			{
				// Without a document output actually exposed by the ancestor there is nothing to reach the
				// source id through, so the whole candidate contributes nothing.
				continue;
			}

			$relationFields = $this->resolveRelationFields($resolver, $sourceDocumentType, $targetDocumentType);
			foreach ($relationFields as $fieldCode => $descriptor)
			{
				// Only sourcePropertyId is transported; the descriptor's `required` flag is intentionally
				// dropped (autofillMap is a plain string map) and reserved for future server-side required
				// pre-validation. sourcePropertyId is a field INSIDE the source document, reached via the
				// ancestor's document output accessor, so it is not looked up among `outputs`.
				$sourcePropertyId = (string)($descriptor['sourcePropertyId'] ?? '');
				if ($sourcePropertyId === '')
				{
					continue;
				}

				$targetFields[(string)$fieldCode][] = '{=' . $blockId . ':' . $documentOutput . '.' . $sourcePropertyId . '}';
			}
		}

		$autofillMap = [];
		foreach ($targetFields as $fieldCode => $matches)
		{
			$distinct = array_values(array_unique($matches));
			if (count($distinct) === 1)
			{
				$autofillMap[$fieldCode] = $distinct[0];
			}
			// 0 or >1 distinct candidates: leave the field empty (frontend fills it).
		}

		return $autofillMap;
	}

	/**
	 * @return array<string, array{sourcePropertyId: string, required: bool}>
	 */
	private function resolveRelationFields(
		RelationFieldResolver $resolver,
		array $sourceDocumentType,
		array $targetDocumentType,
	): array
	{
		$key = $this->cacheKey($sourceDocumentType, $targetDocumentType);
		if (!array_key_exists($key, $this->relationCache))
		{
			$this->relationCache[$key] = $resolver->resolveRelationFields($sourceDocumentType, $targetDocumentType);
		}

		return $this->relationCache[$key];
	}

	private function cacheKey(array $sourceDocumentType, array $targetDocumentType): string
	{
		return implode(':', $sourceDocumentType) . '=>' . implode(':', $targetDocumentType);
	}
}
