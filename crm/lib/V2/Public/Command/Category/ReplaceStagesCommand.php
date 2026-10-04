<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Command\Category;

use Bitrix\Crm\V2\Internal\Service\Category\Handler\ReplaceStagesCommandHandler;
use Bitrix\Crm\V2\Internal\Service\Category\Matching\StageInput;
use Bitrix\Crm\V2\Internal\Service\Category\StageSetReplacer;
use Bitrix\Crm\V2\Public\Entity\Category\CategoryError;
use Bitrix\Crm\V2\Public\Entity\Category\Stage;
use Bitrix\Crm\V2\Public\Entity\Category\StageCollection;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Replaces the stages of one category (pipeline) with the ones the caller sends, in one operation
 * and in one transaction.
 *
 * ```php
 * $stages = new StageCollection(
 *     (new Stage())->setStageId('DT128_5:CLIENT')->setName('Client'),
 *     (new Stage())->setName('Negotiation')->setColor('#112233'),
 * );
 * $result = (new ReplaceStagesCommand($entityType, $categoryId, $stages, $userId))->run();
 * // $stages now holds the stages the category has, in order
 * ```
 *
 * A stage is the same stage when the caller sends back the identifier the category knows it by: it
 * is renamed and moved where the caller put it, keeping its automation, its permissions and the
 * items standing on it. A stage sent without an identifier is created, and a stage of the category
 * the caller does not send at all is deleted - so an empty collection is not a request that changes
 * nothing, it is a request for a category with no stages of its own left.
 *
 * The final and the system stages of a category are none of the caller's business here. They are
 * neither renamed, deleted nor reordered, and asking to change one is refused with
 * {@see CategoryError::OPERATION_NOT_ALLOWED_FOR_STAGE} rather than ignored; sending one back
 * unchanged is allowed and simply means nothing. An identifier the category does not hold is
 * {@see CategoryError::STAGE_NOT_FOUND} and never a stage created in its place. A stage items still
 * stand on is {@see CategoryError::DEPENDENT_ITEMS_EXIST}.
 *
 * ATOMICITY. Whatever the refusal, the category is left exactly as it was - including the deletions
 * and the renames of the same replacement that had already gone through before it.
 *
 * THE ANSWER. A successful result is empty; the collection the command was given is what carries
 * the answer, holding the stages the category ended up with - in the order they stand in, the
 * untouched final and system stages among them, and the created stages with the identifiers storage
 * gave them. The stages that went in are replaced rather than updated: the set that comes back is
 * not the set that went in. How much the replacement changed is on the command itself
 * ({@see self::getAddedCount()}, {@see self::getRenamedCount()}, {@see self::getDeletedCount()}).
 *
 * Only the name and the colour of a stage are the caller's to send. Where a stage stands is said by
 * the order of the collection, and its semantics is the domain's to decide - a stage this command
 * creates is a process stage. A field it does not write is refused with
 * {@see CategoryError::FIELD_NOT_WRITABLE} naming it, rather than dropped on the way; a field set to
 * `null` is not a field that was sent, so it is neither written nor refused. The category is named by
 * the command, so the one a stage of the collection carries is not read.
 *
 * An entity type whose stages do not live in categories - Lead and Quote have stages without
 * categories, Contact and Company categories without stages - is refused with
 * {@see CategoryError::OPERATION_NOT_ALLOWED_FOR_STAGE}, a category the user may not read with
 * {@see CategoryError::CATEGORY_NOT_FOUND}, and one the user may read but not configure with
 * {@see \Bitrix\Crm\Controller\ErrorCode::ACCESS_DENIED}.
 */
final class ReplaceStagesCommand extends AbstractCategoryCommand
{
	/** The fields of a stage a group replacement carries. */
	private const WRITABLE_FIELDS = [Stage::name, Stage::color];

	private int $addedCount = 0;
	private int $renamedCount = 0;
	private int $deletedCount = 0;

	public function __construct(
		private readonly EntityType $entityType,
		private readonly int $categoryId,
		private readonly StageCollection $stages,
		int $userId,
	)
	{
		parent::__construct($userId);
	}

	public function getEntityType(): EntityType
	{
		return $this->entityType;
	}

	public function getCategoryId(): int
	{
		return $this->categoryId;
	}

	public function getStages(): StageCollection
	{
		return $this->stages;
	}

	/**
	 * How many stages the replacement created. `0` until it has run successfully.
	 */
	public function getAddedCount(): int
	{
		return $this->addedCount;
	}

	/**
	 * How many stages of the category the replacement wrote. A stage the caller sent back exactly as
	 * it was counts among them: what the counts describe is the replacement that was asked for.
	 */
	public function getRenamedCount(): int
	{
		return $this->renamedCount;
	}

	/**
	 * How many stages the replacement deleted - the ones of the category the caller did not send.
	 */
	public function getDeletedCount(): int
	{
		return $this->deletedCount;
	}

	protected function execute(): Result
	{
		$unwritable = self::refuseUnwritableFields($this->stages);
		if ($unwritable !== null)
		{
			return $unwritable;
		}

		$result = (new ReplaceStagesCommandHandler())->handle(
			$this->entityType,
			$this->getUserId(),
			$this->categoryId,
			self::inputOf($this->stages),
		);
		if (!$result->isSuccess())
		{
			return $result;
		}

		$this->rememberChangeCounts($result);

		return self::applyStageSetResult($this->stages, $result);
	}

	/**
	 * The refusal for a stage carrying a field this command does not write, or `null` when every
	 * stage of the collection carries only what it may.
	 *
	 * The position and the semantics of a stage are set here by the shape of the request itself, and
	 * the system flag by the domain, so a caller that spelled one of them out is told the field is
	 * not written instead of watching the replacement quietly ignore it.
	 *
	 * A field set to `null` is not spelled out at all: `null` says nothing throughout this domain
	 * ({@see Stage}), so it stays out of the write and out of this refusal alike - the same answer the
	 * single-stage and the category writes give it.
	 */
	private static function refuseUnwritableFields(StageCollection $stages): ?Result
	{
		foreach ($stages->getAll() as $stage)
		{
			$given = array_keys(self::stageFieldsToWrite($stage));
			foreach (array_diff($given, self::WRITABLE_FIELDS) as $field)
			{
				return (new Result())->addError(new Error(
					CategoryError::FIELD_NOT_WRITABLE->getMessage(),
					CategoryError::FIELD_NOT_WRITABLE->value,
					['field' => $field],
				));
			}
		}

		return null;
	}

	/**
	 * The stages of the request as the domain reads them: an identifier where the caller sent one,
	 * the name the stage must end up with, and the colour if the caller means to change it.
	 *
	 * A stage without a name is sent on as an empty one and refused by the domain with
	 * {@see CategoryError::FIELD_VALUE_NOT_ALLOWED} naming the field, the way a single stage without a
	 * name is - a name of nothing but blanks among them, and a stage the caller means to rename to
	 * nothing as much as one it means to create that way.
	 *
	 * @return StageInput[]
	 */
	private static function inputOf(StageCollection $stages): array
	{
		return $stages->map(static fn (Stage $stage): StageInput => new StageInput(
			$stage->getStageId(),
			$stage->getName() ?? '',
			$stage->getColor(),
		));
	}

	private function rememberChangeCounts(Result $result): void
	{
		$counts = $result->getData()[StageSetReplacer::DATA_KEY_CHANGE_COUNTS];

		$this->addedCount = (int)$counts['added'];
		$this->renamedCount = (int)$counts['renamed'];
		$this->deletedCount = (int)$counts['deleted'];
	}
}
