<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\CustomController\Category;

use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\Integration\Analytics\Builder\FunnelAnalytics;
use Bitrix\Crm\Integration\Analytics\Dictionary;
use Bitrix\Crm\V2\Infrastructure\Rest\CustomController\AbstractController;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Category\CategoryDtoMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Dto\Mapping\Category\StageDtoMapper;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Category\AddCategoryRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Category\DeleteCategoryRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Category\GetCategoryRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Category\ListCategoryRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Category\Stage\AddStageRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Category\Stage\DeleteStageRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Category\Stage\GetStageRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Category\Stage\ListStageRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Category\Stage\ReplaceStagesRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Category\Stage\UpdateStageRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Category\UpdateCategoryRequest;
use Bitrix\Crm\V2\Infrastructure\Rest\Request\Structure\CategoryPaginationStructure;
use Bitrix\Crm\V2\Public\Command\Category\AddCategoryCommand;
use Bitrix\Crm\V2\Public\Command\Category\AddStageCommand;
use Bitrix\Crm\V2\Public\Command\Category\DeleteCategoryCommand;
use Bitrix\Crm\V2\Public\Command\Category\DeleteStageCommand;
use Bitrix\Crm\V2\Public\Command\Category\ReplaceStagesCommand;
use Bitrix\Crm\V2\Public\Command\Category\UpdateCategoryCommand;
use Bitrix\Crm\V2\Public\Command\Category\UpdateStageCommand;
use Bitrix\Crm\V2\Public\Command\Item\Scope;
use Bitrix\Crm\V2\Public\Entity\Category\CategoryError;
use Bitrix\Crm\V2\Public\Entity\Category\Stage;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\Provider\Category\CategoryProvider;
use Bitrix\Crm\V2\Public\Provider\Category\StageProvider;
use Bitrix\Main\Provider\Params\Pager;
use Bitrix\Main\Result;
use Bitrix\Main\Validation\Group\ValidationGroup;
use Bitrix\Rest\V3\Attribute\RequiredGroup;
use Bitrix\Rest\V3\Dto\Dto;
use Bitrix\Rest\V3\Dto\Validation\FieldEditableValidator;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Exception\EntityNotFoundException;
use Bitrix\Rest\V3\Exception\RestException;
use Bitrix\Rest\V3\Exception\Validation\DtoValidationException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;
use Bitrix\Rest\V3\Interaction\Response\AddResponse;
use Bitrix\Rest\V3\Interaction\Response\DeleteResponse;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;
use Bitrix\Rest\V3\Interaction\Response\UpdateResponse;

/**
 * The categories (pipelines) of a CRM entity type and their stages over REST: `crm.deal.category.*`,
 * `crm.company.category.*`, `crm.contact.category.*` and the stage methods of the entity types that
 * have stages.
 *
 * One controller serves the whole family, and the entity type is not part of the request: it is the
 * trusted invariant of the route, auto-wired from `SchemaProvider` as an {@see EntityType}
 * ({@see AbstractController::getEntityType()}). A client cannot address another entity type by
 * sending one.
 *
 * Every action here does the same three things and nothing else: it translates the request into the
 * types of the domain, runs exactly one scenario of the public group `Category`, and translates its
 * answer back. Permissions, the invariants of a category and every touch of storage belong to that
 * scenario - the controller neither repeats them nor works around them.
 */
class Category extends AbstractController
{
	public function addAction(AddCategoryRequest $request, EntityType $entityType): AddResponse
	{
		$dto = $request->fields->convertToDto((RequiredGroup::Add)->value);
		$category = $this->getMapper($entityType)->getCategoryByDto($dto);

		$result =
			(new AddCategoryCommand($category, $this->userId))
				->setScope(Scope::Rest)
				->run()
		;

		if (!$result->isSuccess())
		{
			throw self::refusalOf($result);
		}

		self::report(new FunnelAnalytics\Funnel\CreateEvent(section: Dictionary::SECTION_REST));

		// A successful command fills the category it was given, so the identifier of the new one is
		// already here and a read after write would buy nothing.
		return new AddResponse((int)$category->getId());
	}

	/**
	 * An empty `fields` is answered by the scenario rather than short-circuited here: it is the only
	 * thing that can tell whether the category exists and whether the caller may configure it, and
	 * answering a changing method by the read right alone would be wrong.
	 */
	public function updateAction(UpdateCategoryRequest $request, EntityType $entityType): UpdateResponse
	{
		$dto = $request->fields->convertToDto((RequiredGroup::Update)->value);
		$category = $this->getMapper($entityType)->getCategoryByDto($dto)->setId($request->id);

		$result =
			(new UpdateCategoryCommand($category, $this->userId))
				->setScope(Scope::Rest)
				->run()
		;

		if (!$result->isSuccess())
		{
			throw self::categoryRefusalOf($result, $request->id);
		}

		self::report(new FunnelAnalytics\Funnel\RenameEvent(section: Dictionary::SECTION_REST));

		return new UpdateResponse();
	}

	public function getAction(GetCategoryRequest $request, EntityType $entityType): GetResponse
	{
		$category = CategoryProvider::forEntityType($entityType, $this->userId)->getById($request->id);
		if ($category === null)
		{
			throw new EntityNotFoundException($request->id);
		}

		return new GetResponse($this->getMapper($entityType)->getDtoByCategory($category, $request->getDtoClass()));
	}

	public function deleteAction(DeleteCategoryRequest $request, EntityType $entityType): DeleteResponse
	{
		$result =
			(new DeleteCategoryCommand($entityType, $request->id, $this->userId))
				->setScope(Scope::Rest)
				->run()
		;

		if (!$result->isSuccess())
		{
			throw self::categoryRefusalOf($result, $request->id);
		}

		self::report(new FunnelAnalytics\Funnel\DeleteEvent(section: Dictionary::SECTION_REST));

		return new DeleteResponse();
	}

	public function listAction(ListCategoryRequest $request, EntityType $entityType): ListResponse
	{
		$categories = CategoryProvider::forEntityType($entityType, $this->userId)->getList(self::pagerOf($request->pagination));

		return new ListResponse(
			$this->getMapper($entityType)->getDtoCollectionByCategories($categories, $request->getDtoClass()),
		);
	}

	/**
	 * The answer carries the whole new stage rather than its identifier alone, and that is a
	 * deviation of the family worth naming. {@see AddResponse} publishes an `int` identifier, while a
	 * stage is identified by a string that names its category too - `C2:NEW`. Of the standard shapes
	 * {@see GetResponse} is the one that fits, and returning the created entity is also what a
	 * hand-written `add` of REST v3 is told to answer; a response type of our own is not on offer.
	 */
	public function addStageAction(AddStageRequest $request, EntityType $entityType): GetResponse
	{
		$dto = $request->fields->convertToDto((RequiredGroup::Add)->value);
		$stage = $this->getStageMapper()->getStageByDto($dto);

		$result =
			(new AddStageCommand($entityType, $stage, $this->userId))
				->setScope(Scope::Rest)
				->run()
		;

		if (!$result->isSuccess())
		{
			throw self::categoryRefusalOf($result, (int)$stage->getCategoryId());
		}

		self::report(new FunnelAnalytics\Stage\CreateEvent(section: Dictionary::SECTION_REST));

		// A successful command fills the stage it was given, so the whole of the new stage - its
		// identifier among it - is already here and a read after write would buy nothing.
		return new GetResponse($this->getStageMapper()->getDtoByStage($stage, $request->getDtoClass()));
	}

	/**
	 * An empty `fields` is answered by the scenario rather than short-circuited here, for the reason
	 * {@see self::updateAction()} gives.
	 */
	public function updateStageAction(UpdateStageRequest $request, EntityType $entityType): UpdateResponse
	{
		$dto = $request->fields->convertToDto((RequiredGroup::Update)->value);
		self::refuseCategoryOfAnUpdate($dto);

		$stored = $this->stageOf($entityType, $request->id);
		if ($stored === null)
		{
			throw self::stageNotFound();
		}

		$stage =
			$this->getStageMapper()->getStageByDto($dto)
				->setStageId($request->id)
				->setCategoryId($stored->getCategoryId())
		;

		$result =
			(new UpdateStageCommand($entityType, $stage, $this->userId))
				->setScope(Scope::Rest)
				->run()
		;

		if (!$result->isSuccess())
		{
			throw self::stageRefusalOf($result);
		}

		self::report(new FunnelAnalytics\Stage\RenameEvent(section: Dictionary::SECTION_REST));

		return new UpdateResponse();
	}

	public function getStageAction(GetStageRequest $request, EntityType $entityType): GetResponse
	{
		$stage = $this->stageOf($entityType, $request->id);
		if ($stage === null)
		{
			throw self::stageNotFound();
		}

		return new GetResponse($this->getStageMapper()->getDtoByStage($stage, $request->getDtoClass()));
	}

	public function deleteStageAction(DeleteStageRequest $request, EntityType $entityType): DeleteResponse
	{
		$stage = $this->stageOf($entityType, $request->id);
		if ($stage === null)
		{
			throw self::stageNotFound();
		}

		$result =
			(new DeleteStageCommand($entityType, (int)$stage->getCategoryId(), $request->id, $this->userId))
				->setScope(Scope::Rest)
				->run()
		;

		if (!$result->isSuccess())
		{
			throw self::stageRefusalOf($result);
		}

		self::report(new FunnelAnalytics\Stage\DeleteEvent(section: Dictionary::SECTION_REST));

		return new DeleteResponse();
	}

	/**
	 * The category the stages are asked for is checked before they are read: the stage provider
	 * answers an unreadable and an unknown category with an empty set, and a client that named a
	 * category it may not have must learn that rather than be told the category is empty.
	 */
	public function listStageAction(ListStageRequest $request, EntityType $entityType): ListResponse
	{
		$categoryId = self::categoryIdOf($request);
		if (CategoryProvider::forEntityType($entityType, $this->userId)->getById($categoryId) === null)
		{
			throw new EntityNotFoundException($categoryId);
		}

		$stages =
			StageProvider::forEntityType($entityType, $this->userId)
				->getList($categoryId, self::pagerOf($request->pagination))
		;

		return new ListResponse($this->getStageMapper()->getDtoCollectionByStages($stages, $request->getDtoClass()));
	}

	/**
	 * The one method of the family that is not a CRUD of a single object: the whole set of stages of a
	 * category is replaced by the set the request carries, in one operation.
	 *
	 * What the replacement means - which stage is the same stage, which is created, which is deleted
	 * and which may not be touched at all - is settled by the scenario alone
	 * ({@see ReplaceStagesCommand}). The controller carries the set there and the set the category
	 * ended up with back, and the answer is the standard list of the stages rather than a shape of its
	 * own: the caller gets the same stages `stage.list` would give it.
	 */
	public function replaceStagesAction(ReplaceStagesRequest $request, EntityType $entityType): ListResponse
	{
		$stages = $this->getStageMapper()->getStageCollectionByInputDtos($request->convertToStages());

		$command =
			(new ReplaceStagesCommand($entityType, $request->categoryId, $stages, $this->userId))
				->setScope(Scope::Rest)
		;

		$result = $command->run();
		if (!$result->isSuccess())
		{
			throw self::categoryRefusalOf($result, $request->categoryId);
		}

		self::reportReplacement($command);

		// A successful command fills the collection it was given with the stages the category ended up
		// with, in the order they stand in, so a read after write would buy nothing.
		return new ListResponse($this->getStageMapper()->getDtoCollectionByStages($stages, $request->getDtoClass()));
	}

	private function getMapper(EntityType $entityType): CategoryDtoMapper
	{
		return new CategoryDtoMapper($entityType);
	}

	private function getStageMapper(): StageDtoMapper
	{
		return new StageDtoMapper();
	}

	/**
	 * The stage $stageId of $entityType, whichever of its categories holds it, or `null` when none of
	 * the categories this user may read does.
	 *
	 * A stage is addressed from the outside by its identifier alone, while every scenario of the
	 * domain reads and writes a stage inside its category. Which category an identifier names is not
	 * something the transport may work out on its own: the forms of an identifier are the business of
	 * the domain, and reading a category out of one here would put a rule of the domain into REST. So
	 * the domain is asked instead ({@see StageProvider::findById()}).
	 */
	private function stageOf(EntityType $entityType, string $stageId): ?Stage
	{
		return StageProvider::forEntityType($entityType, $this->userId)->findById($stageId);
	}

	/**
	 * The category of the list. The framework has already seen to it that the filter names it by
	 * equality and that the value is a number of the contract.
	 */
	private static function categoryIdOf(ListStageRequest $request): int
	{
		return (int)$request->filter->getSimpleFilterConditions()[Stage::categoryId];
	}

	/**
	 * Refuses an update that carries `categoryId`: a stage does not move between categories, and a
	 * field a client sent is never dropped in silence.
	 *
	 * The framework does not refuse it on its own - a field declared required in any group never
	 * reaches the check of what may be written ({@see \Bitrix\Rest\V3\Dto\DtoValidatorHelper}) - so
	 * the refusal is asked of the very validator that was skipped, and the answer a client gets for
	 * this field is the one every other field outside a write gives it.
	 */
	private static function refuseCategoryOfAnUpdate(Dto $dto): void
	{
		$field = $dto->getFields()[Stage::categoryId];
		if ($field === null || !isset($dto->{Stage::categoryId}))
		{
			return;
		}

		$field->setValue($dto->{Stage::categoryId});

		$refusal =
			(new FieldEditableValidator(ValidationGroup::create((RequiredGroup::Update)->value)))
				->validate($field)
		;

		if (!$refusal->isSuccess())
		{
			throw new DtoValidationException($refusal->getErrors());
		}
	}

	/**
	 * The page a list is asked for. A request that names no paging gets the default page of the
	 * framework rather than the whole set: an unbounded external read is not on offer.
	 *
	 * What a page may be is settled by {@see CategoryPaginationStructure} alone - a page that reaches
	 * here has already been refused if it is one {@see Pager} would throw on.
	 */
	private static function pagerOf(?CategoryPaginationStructure $pagination): Pager
	{
		if ($pagination === null)
		{
			return new Pager();
		}

		return new Pager($pagination->getLimit(), $pagination->getOffset());
	}

	/**
	 * What REST answers to a refused scenario that addresses the category $categoryId.
	 *
	 * A category the user may not read is refused as missing by the domain itself, so a caller
	 * without access to a category learns exactly what a caller naming a category that is not there
	 * learns.
	 */
	private static function categoryRefusalOf(Result $result, int $categoryId): RestException
	{
		if (self::refusalCodeOf($result) === CategoryError::CATEGORY_NOT_FOUND->value)
		{
			return new EntityNotFoundException($categoryId);
		}

		return self::refusalOf($result);
	}

	/**
	 * What REST answers to a refused scenario that addresses one stage.
	 *
	 * A stage that is not there, a category that is not there and a category the user may not read
	 * are one and the same answer: the identifier of a stage names its category as well, so telling
	 * the three apart would tell a caller which categories a portal has.
	 *
	 * {@see EntityNotFoundException}, the answer the family gives for a missing category, is not on
	 * offer here - it publishes the identifier it was given and takes an `int`, while a stage is
	 * identified by a string. The refusal of the domain keeps the status of a missing object and names
	 * the stage instead of a number the transport does not have.
	 */
	private static function stageRefusalOf(Result $result): RestException
	{
		$isMissing = in_array(
			self::refusalCodeOf($result),
			[CategoryError::CATEGORY_NOT_FOUND->value, CategoryError::STAGE_NOT_FOUND->value],
			true,
		);

		return $isMissing ? self::stageNotFound() : self::refusalOf($result);
	}

	private static function stageNotFound(): RequestValidationException
	{
		return new RequestValidationException([CategoryError::STAGE_NOT_FOUND->toError()]);
	}

	/**
	 * What REST answers to a refusal that names no object: a user who may not perform the action at
	 * all is answered by access, and everything else is something the client can fix in its input.
	 *
	 * The absence of an object is not decided here - which of the codes of the domain means absence
	 * depends on what the action addresses.
	 */
	private static function refusalOf(Result $result): RestException
	{
		if (self::refusalCodeOf($result) === ErrorCode::ACCESS_DENIED)
		{
			return new AccessDeniedException();
		}

		return new RequestValidationException($result->getErrors());
	}

	/**
	 * The code of a refusal of the domain: a case of {@see CategoryError} or
	 * {@see ErrorCode::ACCESS_DENIED}. A refused scenario of the group carries exactly one error.
	 */
	private static function refusalCodeOf(Result $result): string
	{
		$errors = $result->getErrors();

		return (string)(($errors[0] ?? null)?->getCode() ?? '');
	}

	/**
	 * How much of the category a group replacement changed, as the three events the funnel analytics
	 * of stages has for it. A count of zero is not reported at all: the replacement did nothing of
	 * that kind.
	 */
	private static function reportReplacement(ReplaceStagesCommand $command): void
	{
		$events = [];
		if ($command->getAddedCount() > 0)
		{
			$events[] = new FunnelAnalytics\Stage\CreateEvent(
				section: Dictionary::SECTION_REST,
				count: $command->getAddedCount(),
			);
		}
		if ($command->getRenamedCount() > 0)
		{
			$events[] = new FunnelAnalytics\Stage\RenameEvent(
				section: Dictionary::SECTION_REST,
				count: $command->getRenamedCount(),
			);
		}
		if ($command->getDeletedCount() > 0)
		{
			$events[] = new FunnelAnalytics\Stage\DeleteEvent(
				section: Dictionary::SECTION_REST,
				count: $command->getDeletedCount(),
			);
		}

		self::report(...$events);
	}

	/**
	 * Tells analytics what a method of the family has just done, under the section of REST.
	 *
	 * The report is made here rather than by the scenario the action ran: a scenario of the group has
	 * consumers of its own, each with its own section - the AI integration reports the very same
	 * group replacement of stages as `ai`
	 * ({@see \Bitrix\Crm\Integration\AI\Function\Deal\Category\UpdateStageList}) - and a second report
	 * from inside the scenario would count one action twice.
	 *
	 * Only an action that has already succeeded is reported, since a refused one leaves here as an
	 * exception long before this point. And a report never decides the answer of a method: analytics
	 * is a side effect of an action that has happened, so whatever goes wrong while it is being made
	 * leaves the successful action successful.
	 */
	private static function report(FunnelAnalytics\FunnelAnalyticsBaseEvent ...$events): void
	{
		foreach ($events as $event)
		{
			try
			{
				$event
					->setStatus(Dictionary::STATUS_SUCCESS)
					->buildEvent()
					->send()
				;
			}
			catch (\Throwable)
			{
				// see the note above: a report of an action is never the answer to it
			}
		}
	}
}
