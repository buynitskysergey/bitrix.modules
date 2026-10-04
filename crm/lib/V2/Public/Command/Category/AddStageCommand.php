<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Category;

use Bitrix\Crm\V2\Internal\Service\Category\Handler\AddStageCommandHandler;
use Bitrix\Crm\V2\Public\Entity\Category\CategoryError;
use Bitrix\Crm\V2\Public\Entity\Category\Stage;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\Result;

/**
 * Creates a stage in a category (pipeline) of an entity type.
 *
 * ```php
 * $stage = (new Stage())->setCategoryId($categoryId)->setName('Negotiation');
 * $result = (new AddStageCommand(EntityType::deal(), $stage, $userId))->run();
 * // $stage->getStageId() on success
 * ```
 *
 * The entity type is named by the command and the category by the stage: a stage means nothing
 * outside the category it stands in, and unlike a category it does not carry its entity type. On
 * success the same stage is filled with what storage holds, its whole identifier included - `C2:NEW`
 * for a Deal category, `DT128_5:NEW` for a smart process one.
 *
 * Where the stage goes is the caller's to choose through the position and the semantics, and the
 * rules around both belong to the domain: a second success stage, a failure stage ahead of the
 * success one and a process stage behind it are refused with
 * {@see CategoryError::OPERATION_NOT_ALLOWED_FOR_STAGE}. A stage given no position of its own goes
 * after the last process stage of the category rather than at the end of the set.
 *
 * An entity type whose stages do not live in categories - Lead and Quote have stages without
 * categories, Contact and Company categories without stages - is refused with the same code, a
 * category the user may not read with {@see CategoryError::CATEGORY_NOT_FOUND}, and one the user may
 * read but not configure with {@see \Bitrix\Crm\Controller\ErrorCode::ACCESS_DENIED}. A field the
 * domain does not write - the system flag of a stage - is refused with
 * {@see CategoryError::FIELD_NOT_WRITABLE} naming it, and a name of nothing but blanks with
 * {@see CategoryError::FIELD_VALUE_NOT_ALLOWED} naming it the same way.
 */
final class AddStageCommand extends AbstractCategoryCommand
{
	public function __construct(
		private readonly EntityType $entityType,
		private readonly Stage $stage,
		int $userId,
	)
	{
		parent::__construct($userId);
	}

	public function getEntityType(): EntityType
	{
		return $this->entityType;
	}

	public function getStage(): Stage
	{
		return $this->stage;
	}

	/**
	 * @throws ArgumentException The stage names no category.
	 */
	protected function execute(): Result
	{
		$result = (new AddStageCommandHandler())->handle(
			$this->entityType,
			$this->getUserId(),
			self::stageCategoryIdOf($this->stage),
			self::stageFieldsToWrite($this->stage),
		);

		return self::applyStageWriteResult($this->stage, $result);
	}
}
