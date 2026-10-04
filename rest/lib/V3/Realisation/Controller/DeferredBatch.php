<?php

declare(strict_types=1);

namespace Bitrix\Rest\V3\Realisation\Controller;

use Bitrix\Main\Request;
use Bitrix\Rest\Internal\Entity\DeferredBatch\Status;
use Bitrix\Rest\Internal\Model\DeferredBatch\Params;
use Bitrix\Rest\Internal\Repository\DeferredBatchRepository;
use Bitrix\Rest\Internal\Service\DeferredBatch\FileStorage;
use Bitrix\Rest\Internal\Service\DeferredBatchService;
use Bitrix\Rest\V3\Attribute\DtoType;
use Bitrix\Rest\V3\Attribute\RequiredGroup;
use Bitrix\Rest\V3\Attribute\Scope;
use Bitrix\Rest\V3\Controller\OrmActionTrait;
use Bitrix\Rest\V3\Controller\RestController;
use Bitrix\Rest\V3\Data\OrmRepository;
use Bitrix\Rest\V3\DeferredBatch\Exception\DeferredBatchException;
use Bitrix\Rest\V3\Exception\AccessDeniedException;
use Bitrix\Rest\V3\Exception\EntityNotFoundException;
use Bitrix\Rest\V3\Interaction\Request\AddRequest;
use Bitrix\Rest\V3\Interaction\Request\DeleteRequest;
use Bitrix\Rest\V3\Interaction\Request\GetRequest;
use Bitrix\Rest\V3\Interaction\Request\IdRequest;
use Bitrix\Rest\V3\Interaction\Request\ListRequest;
use Bitrix\Rest\V3\Interaction\Response\BooleanResponse;
use Bitrix\Rest\V3\Interaction\Response\GetResponse;
use Bitrix\Rest\V3\Interaction\Response\ListResponse;
use Bitrix\Rest\V3\Realisation\Dto\DeferredBatchDto;
use Bitrix\Rest\V3\Realisation\Response\DownloadUrlResponse;
use Bitrix\Rest\V3\Structure\Filtering\Condition;
use Bitrix\Rest\V3\Structure\Filtering\FilterStructure;
use Bitrix\Rest\V3\Structure\Filtering\Operator;
use Bitrix\Rest\V3\Structure\SelectStructure;
use CRestUtil;

#[DtoType(DeferredBatchDto::class)]
final class DeferredBatch extends RestController
{
	use OrmActionTrait;

	private const DEFAULT_LIST_SELECT = [
		'id',
		'status',
		'createdAt',
		'updatedAt',
		'resultFileId',
		'errorMessage',
	];

	private OrmRepository $repository;

	public function __construct(?Request $request = null)
	{
		parent::__construct($request);
		$this->repository = new OrmRepository(DeferredBatchDto::class);
	}

	#[Scope(\CRestUtil::GLOBAL_SCOPE)]
	public function addAction(AddRequest $request): GetResponse
	{
		/** @var DeferredBatchDto $dto */
		$dto = $request->fields->convertToDto((RequiredGroup::Add)->value);
		$userId = (int)$this->getCurrentUser()->getId();
		$availableScopes = $this->getServer()->getAvailableScopes();

		$queryParams = $this->getQueryParams() ?? [];
		if (isset($queryParams['any']) && str_contains($queryParams['any'], 'rest.deferredbatch.add'))
		{
			$queryParams['any'] = str_replace('rest.deferredbatch.add', 'batch', $queryParams['any']);
		}

		if (isset($queryParams['fields']))
		{
			unset($queryParams['fields']);
		}

		$params = [
			Params::Language->value => $this->getResponseLanguage(),
			Params::Query->value => array_merge($queryParams, $this->getServer()->getAuth()),
			Params::Scopes->value => $availableScopes,
		];

		$service = new DeferredBatchService();

		$result = $service->register($dto->commands, $userId, $availableScopes, $params);
		if (!$result->isSuccess())
		{
			$errors = $result->getErrorCollection();
			$firstError = null;
			foreach ($errors as $e)
			{
				$firstError = $e;
				break;
			}
			throw new DeferredBatchException(
				$firstError?->getMessage() ?? 'Failed to register deferred batch',
				$firstError?->getCode() ?? 'ERROR',
			);
		}

		$id = (int)$result->getData()['id'];

		return new GetResponse($this->getDtoByIdOrThrowEntityNotFoundException($id, null, $userId));
	}

	#[Scope(\CRestUtil::GLOBAL_SCOPE)]
	public function listAction(ListRequest $request): ListResponse
	{
		$userId = (int)$this->getCurrentUser()->getId();

		$filterStructure = new FilterStructure();
		$condition = new Condition('userId', Operator::Equal, $userId);
		$filterStructure->addCondition($condition);
		if ($request->filter !== null)
		{
			$filterStructure->addCondition($request->filter);
		}

		$select = $request->select;
		if ($select === null)
		{
			$select = SelectStructure::create(
				self::DEFAULT_LIST_SELECT,
				DeferredBatchDto::class,
				$request,
			);
		}

		$collection = $this->repository->getAll(
			$select,
			$filterStructure,
			$request->order,
			$request->pagination,
		);

		return new ListResponse($collection);
	}

	#[Scope(\CRestUtil::GLOBAL_SCOPE)]
	public function getAction(GetRequest $request): GetResponse
	{
		$dto = $this->getDtoByIdOrThrowEntityNotFoundException((int)$request->id, $request->select);

		return new GetResponse($dto);
	}

	/**
	 * Returns a signed download URL for the batch result file.
	 * Call after status=done: rest.deferredbatch.downloadResult with {"id": N}.
	 */
	#[Scope(\CRestUtil::GLOBAL_SCOPE)]
	public function downloadResultAction(IdRequest $request): DownloadUrlResponse
	{
		$dto = $this->getDtoByIdOrThrowEntityNotFoundException((int)$request->id);

		if ($dto->status !== Status::Done->value || $dto->resultFileId === null)
		{
			throw new DeferredBatchException(
				'Deferred batch result is not ready for download',
				'BATCH_RESULT_NOT_READY',
			);
		}

		$server = $this->getServer();
		if ($server === null)
		{
			throw new DeferredBatchException(
				'REST server context is not available',
				'SERVER_CONTEXT_MISSING',
			);
		}

		return new DownloadUrlResponse(CRestUtil::getDownloadUrl(
			['deferredBatchId' => $dto->id],
			$server,
		));
	}

	#[Scope(\CRestUtil::GLOBAL_SCOPE)]
	public function deleteAction(DeleteRequest $request): BooleanResponse
	{
		$userId = (int)$this->getCurrentUser()->getId();
		if ($userId <= 0)
		{
			throw new AccessDeniedException();
		}

		$dto = $this->getDtoByIdOrThrowEntityNotFoundException((int)$request->id, null, $userId);
		$resultFileId = $dto->resultFileId ?? null;

		$batchRepository = new DeferredBatchRepository();
		if (!$batchRepository->deleteIfNotProcessing((int)$dto->id, $userId))
		{
			$current = $batchRepository->getById((int)$dto->id);
			if ($current === null)
			{
				throw new EntityNotFoundException((int)$dto->id);
			}

			throw new DeferredBatchException(
				'Cannot delete deferred batch while it is processing',
				'BATCH_PROCESSING',
			);
		}

		if ($resultFileId !== null)
		{
			$fileStorage = new FileStorage();
			if (!$fileStorage->delete($resultFileId))
			{
				throw new DeferredBatchException(
					'Failed to delete deferred batch file result',
					'FILE_DELETE_FAILED',
				);
			}
		}

		return new BooleanResponse(true);
	}

	private function getDtoByIdOrThrowEntityNotFoundException(int $id, ?SelectStructure $select = null, ?int $userId = null): DeferredBatchDto
	{
		if ($userId === null)
		{
			$userId = (int)$this->getCurrentUser()->getId();
			if ($userId <= 0)
			{
				throw new AccessDeniedException();
			}
		}
		$filter = new FilterStructure();
		$condition = new Condition('userId', Operator::Equal, $userId);
		$filter->addCondition($condition);
		$filter->where('id', $id);

		/** @var DeferredBatchDto $dto */
		$dto = $this->repository->getOneWith($select, $filter);

		if ($dto === null)
		{
			throw new EntityNotFoundException($id);
		}

		return $dto;
	}
}
