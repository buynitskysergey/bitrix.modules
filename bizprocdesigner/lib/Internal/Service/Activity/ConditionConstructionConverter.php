<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Service\Activity;

use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule\ConditionExpressionDto;
use Bitrix\BizprocDesigner\Infrastructure\Dto\Activity\Complex\Rule\ConstructionDto;
use Bitrix\BizprocDesigner\Infrastructure\Enum\ConstructionType;
use Bitrix\BizprocDesigner\Internal\Trait\ConditionValueResolver;

/**
 * The single place a chain of condition constructions becomes the list of condition entries
 * `CBPMixedCondition::evaluate()` reads. Where that list is then stored - a child branching activity or a
 * property of the node - is decided by the caller and not here.
 */
final class ConditionConstructionConverter
{
	use ConditionValueResolver;

	/**
	 * Entries of one condition group: the run of condition constructions that starts at $startPosition and
	 * ends at the first construction that is not a condition, or at the next `condition:if` - a second `if`
	 * opens the next group and belongs to it, not to this one. A construction whose field is not filled in
	 * is skipped without ending the run - an unfinished row must not cut off the rows filled in below it.
	 *
	 * @param list<ConstructionDto> $constructionList Constructions of the rule, in the order they arrived.
	 * @param array|null $conditionDocumentType Document type the `Document` object of the condition addresses
	 *   while that is not the one of the template, see {@see self::resolveConditionDocumentType()}.
	 * @return list<array<string, mixed>> Condition entries, empty when the group carries no filled row.
	 */
	public function convertChain(
		array $constructionList,
		int $startPosition,
		array $documentType,
		?array $conditionDocumentType = null,
	): array
	{
		$entries = [];

		$constructionListCount = count($constructionList);
		for ($i = $startPosition; $i < $constructionListCount; $i++)
		{
			$construction = $constructionList[$i];
			if (
				!$construction->constructionType->isCondition()
				|| !$construction->expression instanceof ConditionExpressionDto
				|| ($i > $startPosition && $construction->constructionType === ConstructionType::IF_CONDITION)
			)
			{
				break;
			}

			if ($construction->expression->field === null)
			{
				continue;
			}

			$entries[] = $this->convertExpression(
				$construction->expression,
				$construction->constructionType,
				$documentType,
				$conditionDocumentType,
			);
		}

		return $entries;
	}

	/** `joiner` binds the entry to the previous one: `0` for AND, `1` for OR; ignored on the first entry. */
	private function convertExpression(
		ConditionExpressionDto $expression,
		ConstructionType $constructionType,
		array $documentType,
		?array $conditionDocumentType,
	): array
	{
		$field = $expression->field;

		$value = $this->resolveConditionValue(
			$field,
			$expression->value ?? '',
			$documentType,
			$conditionDocumentType,
		);

		return [
			'object' => $field->object,
			'field' => $field->fieldId,
			'operator' => $expression->operator,
			'value' => $value,
			'joiner' => in_array(
				$constructionType,
				[ConstructionType::AND_CONDITION, ConstructionType::IF_CONDITION],
				true,
			) ? '0' : '1',
		];
	}
}
