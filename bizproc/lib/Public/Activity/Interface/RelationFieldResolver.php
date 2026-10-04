<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Activity\Interface;

/**
 * Design-time contract that maps relation fields between two nodes of a node graph.
 *
 * Unlike runtime resolvers in this namespace, it never receives a live \CBPActivity:
 * on design-time there is no activity instance. The contract is documentType/graph-based -
 * given the documentType triplets of a source (ancestor) node and a target ("Create") node,
 * it tells which source output feeds which target form field.
 *
 * It resolves relations only; it never transforms values. Value transfer is the caller's job.
 *
 * documentType is the standard Bitrix triplet [moduleId, entity, documentType].
 */
interface RelationFieldResolver
{
	public function supports(array $targetDocumentType): bool;

	/**
	 * Returns the relation-field map for creating $targetDocumentType linked to $sourceDocumentType.
	 *
	 * @return array<string, array{sourcePropertyId: string, required: bool}>
	 *   key                - target "Create" form field code
	 *   sourcePropertyId   - source (ancestor) node output code that feeds this target field
	 *   required           - whether the target field is required for the relation
	 */
	public function resolveRelationFields(
		array $sourceDocumentType,
		array $targetDocumentType,
	): array;
}
