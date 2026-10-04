<?php

namespace Bitrix\Crm\Integration\AI\Function\Category\Stage;

use Bitrix\Crm\Integration\AI\Contract\AIFunction;
use Bitrix\Crm\Integration\AI\Function\Category\Dto\Stage\UpdateListParameters;
use Bitrix\Crm\Integration\AI\Function\Category\Dto\Stage\UpdateListStage;
use Bitrix\Crm\Result;
use Bitrix\Crm\V2\Internal\Service\Category\Matching\MatchByPosition;
use Bitrix\Crm\V2\Internal\Service\Category\Matching\PositionalStageInput;
use Bitrix\Crm\V2\Internal\Service\Category\StageSetReplacer;
use Bitrix\Crm\V2\Public\EntityType;

/**
 * The tool of the AI assistant that replaces the process stages of a category with the list a model
 * sent, matched to the ones the category has by position.
 *
 * Everything below the parameters is the group replacement of the domain
 * ({@see StageSetReplacer}), and the only thing this scenario adds to it is the strategy the stages
 * are matched by ({@see MatchByPosition}) and the shape the answer comes back in. The permission,
 * the transaction, the order of the writes and the notification of the kanban all live in the core,
 * shared with REST - the scenario used to run its own copy of them, and a rule kept in two places
 * drifts apart.
 *
 * WHAT THE PORT CHANGED. The right to write is asked with the category as well as the entity type
 * ({@see \Bitrix\Crm\V2\Internal\Service\Category\CategoryAccess::canWriteStages()}), so a
 * contractor category is decided by the warehouse configuration right where the tool used to want
 * CRM administrator - the permission model of the domain rather than one of this tool, and the same
 * one REST is held to. Beside it the entity type now has to support stages at all, where the
 * scenario only required a factory. A refusal answers with the errors of the domain instead of the
 * text of whatever the storage boundary threw. And a stage update that fails is a refusal now: the
 * scenario wrote through {@see \CCrmStatus::Update()}, which hands back the identifier it was given
 * even when the write did not go through, so a rename the boundary turned down used to be reported
 * as a success.
 *
 * WHAT IT KEPT. The contract of {@see AIFunction} and the shape of a successful answer, the
 * positional matching with its workaround, the new stages going in front of the first final stage,
 * the step the resulting set is spaced by, and the notification of the kanban. The change counts are
 * read off the plan rather than off the difference in set sizes, which for this strategy comes to
 * the same numbers.
 */
final class UpdateList implements AIFunction
{
	public function __construct(
		private readonly int $currentUserId,
	)
	{
	}

	public function isAvailable(): bool
	{
		return true;
	}

	public function invoke(...$args): Result
	{
		$parameters = new UpdateListParameters($args);
		if ($parameters->hasValidationErrors())
		{
			return Result::fail($parameters->getValidationErrors());
		}

		// Safe without a check of its own: the parameters only validate for an entity type that has a
		// factory, and every such type is one EntityType wraps.
		$entityType = EntityType::fromId($parameters->entityTypeId);

		$replacement = (new StageSetReplacer())->replace(
			$entityType,
			$this->currentUserId,
			$parameters->categoryId,
			self::stagesOf($parameters),
			new MatchByPosition(),
		);

		if (!$replacement->isSuccess())
		{
			// Every error of the domain, message and code alike: the tool hands the text to the model
			// ({@see \Bitrix\Crm\Integration\AiAssistant\Tools\CategoryUpdateStagesList}), and the
			// boundary answers a refused write with more than one error often enough.
			return (new Result())->addErrors($replacement->getErrors());
		}

		return Result::success(
			changeCounts: $replacement->getData()[StageSetReplacer::DATA_KEY_CHANGE_COUNTS],
		);
	}

	/**
	 * @return PositionalStageInput[]
	 */
	private static function stagesOf(UpdateListParameters $parameters): array
	{
		return array_map(
			static fn(UpdateListStage $stage) => new PositionalStageInput($stage->name, $stage->color),
			$parameters->stages,
		);
	}
}
