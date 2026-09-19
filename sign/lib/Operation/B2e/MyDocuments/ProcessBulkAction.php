<?php

namespace Bitrix\Sign\Operation\B2e\MyDocuments;

use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Sign\Contract\Operation;
use Bitrix\Sign\Item\Document;
use Bitrix\Sign\Item\Member;
use Bitrix\Sign\Repository\DocumentRepository;
use Bitrix\Sign\Repository\MemberRepository;
use Bitrix\Sign\Service\B2e\MyDocumentsGrid\ActionStatusService;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Service\MobileService;
use Bitrix\Sign\Service\Sign\MemberService;
use Bitrix\Sign\Type\DocumentScenario;
use Bitrix\Sign\Type\DocumentStatus;
use Bitrix\Sign\Type\MemberStatus;
use Bitrix\Sign\Type\MyDocumentsGrid\BulkAction;

final class ProcessBulkAction implements Operation
{
	public const CHUNK_SIZE = 5;
	public const LIMIT = 50;
	// grid selection is bounded by the page size, so a larger payload cannot come from the interface
	public const MAX_SOURCE_ITEMS = 500;

	private const STATUS_COMPLETED = 'COMPLETED';
	private const STATUS_PROGRESS = 'PROGRESS';

	private const BRANCH_ACCEPT = 'accept';
	private const BRANCH_REFUSE = 'refuse';
	private const BRANCH_STOP = 'stop';

	private readonly MemberRepository $memberRepository;
	private readonly DocumentRepository $documentRepository;
	private readonly MemberService $memberService;
	private readonly ActionStatusService $actionStatusService;
	private readonly MobileService $mobileService;

	/** @var array<int, array{member: Member, document: Document}> items the action can be applied to */
	private array $allowedItems = [];

	/** @var array<int, bool> outcome of every item of the chunk, in the order of the chunk */
	private array $outcomes = [];

	/** @var array<int, ?string> document title of a chunk item, null when the user must not see it */
	private array $titles = [];

	/** a branch answered with a refusal of the whole request, so its items got no outcome of their own */
	private bool $branchRequestRejected = false;

	/**
	 * @param list<mixed> $memberIds
	 */
	public function __construct(
		private readonly array $memberIds,
		private readonly BulkAction $action,
		private readonly int $currentUserId,
		private readonly int $processedItems = 0,
		private readonly ?int $lastProcessedId = null,
		private readonly string $limitWarning = '',
		?MemberRepository $memberRepository = null,
		?DocumentRepository $documentRepository = null,
		?MemberService $memberService = null,
		?ActionStatusService $actionStatusService = null,
		?MobileService $mobileService = null,
	)
	{
		$container = Container::instance();
		$this->memberRepository = $memberRepository ?? $container->getMemberRepository();
		$this->documentRepository = $documentRepository ?? $container->getDocumentRepository();
		$this->memberService = $memberService ?? $container->getMemberService();
		$this->actionStatusService = $actionStatusService ?? $container->getActionStatusService();
		$this->mobileService = $mobileService ?? $container->getMobileService();
	}

	public function launch(): Result
	{
		if (count($this->memberIds) > self::MAX_SOURCE_ITEMS)
		{
			return $this->createErrorResult('Document list is too large', 'MEMBER_IDS_LIMIT_EXCEEDED');
		}

		$selectedMemberIds = $this->normalizeMemberIds($this->memberIds);
		if ($selectedMemberIds === [])
		{
			return $this->createErrorResult('Document list cannot be empty', 'EMPTY_MEMBER_IDS');
		}

		$sourceItems = count($selectedMemberIds);
		$memberIds = array_slice($selectedMemberIds, 0, self::LIMIT);
		$totalItems = count($memberIds);
		if (!$this->isCursorValid($memberIds, $totalItems))
		{
			return $this->createErrorResult('Invalid process cursor', 'INVALID_CURSOR');
		}

		$chunkIds = array_slice($memberIds, $this->processedItems, self::CHUNK_SIZE);
		if ($chunkIds === [])
		{
			return (new Result())->setData($this->createResponseData(
				status: self::STATUS_COMPLETED,
				processedItems: $this->processedItems,
				totalItems: $totalItems,
				lastProcessedId: $this->lastProcessedId ?? $memberIds[$totalItems - 1],
				succeededMemberIds: [],
				failedItems: [],
				sourceItems: $sourceItems,
			));
		}

		$membersById = $this->loadMembersById($chunkIds);
		$this->classifyChunkItems($chunkIds, $membersById, $this->loadDocumentsById($membersById));
		$this->applyBranchOutcomes();

		$succeededMemberIds = $this->collectSucceededMemberIds();

		// A refusal of the whole request says nothing about single documents, so a step left without a
		// single success is a failure of the step itself. Reporting it as a result would say «none of
		// these can be processed from the grid», while the truth is «nothing worked, try again» - and the
		// remaining chunks would keep going to a service that has already refused the request. Reasons
		// still do not travel: the operator gets the message of the process, not the one of the branch.
		if ($this->branchRequestRejected && $succeededMemberIds === [])
		{
			return $this->createErrorResult('Bulk action step failed', 'BULK_ACTION_STEP_FAILED');
		}

		$processedItems = $this->processedItems + count($chunkIds);

		return (new Result())->setData($this->createResponseData(
			status: $processedItems < $totalItems ? self::STATUS_PROGRESS : self::STATUS_COMPLETED,
			processedItems: $processedItems,
			totalItems: $totalItems,
			lastProcessedId: $chunkIds[array_key_last($chunkIds)],
			succeededMemberIds: $succeededMemberIds,
			failedItems: $this->collectFailedItems(),
			sourceItems: $sourceItems,
		));
	}

	/**
	 * @param list<int> $chunkIds
	 * @return array<int, Member>
	 */
	private function loadMembersById(array $chunkIds): array
	{
		$membersById = [];
		foreach ($this->memberRepository->listByIds($chunkIds, loadEntityNames: false) as $member)
		{
			if ($member->id === null)
			{
				continue;
			}

			$membersById[$member->id] = $member;
		}

		return $membersById;
	}

	/**
	 * @param array<int, Member> $membersById
	 * @return array<int, Document>
	 */
	private function loadDocumentsById(array $membersById): array
	{
		$documentIds = [];
		foreach ($membersById as $member)
		{
			if ($member->documentId !== null)
			{
				$documentIds[$member->documentId] = $member->documentId;
			}
		}

		return $this->documentRepository
			->listByIds(array_values($documentIds))
			->getArrayByIds()
		;
	}

	/**
	 * Ownership and applicability are checked for every item of the chunk, and before any call is made.
	 *
	 * @param list<int> $chunkIds
	 * @param array<int, Member> $membersById
	 * @param array<int, Document> $documentsById
	 */
	private function classifyChunkItems(array $chunkIds, array $membersById, array $documentsById): void
	{
		foreach ($chunkIds as $memberId)
		{
			$member = $membersById[$memberId] ?? null;
			$document = $member?->documentId === null ? null : ($documentsById[$member->documentId] ?? null);
			$this->titles[$memberId] = null;
			$this->outcomes[$memberId] = false;
			if ($member === null || $document === null)
			{
				continue;
			}

			if (!DocumentScenario::isB2EScenario($document->scenario))
			{
				continue;
			}

			if (!$this->memberService->isUserLinksWithMember($member, $document, $this->currentUserId))
			{
				continue;
			}

			$this->titles[$memberId] = $document->title;
			if (!$this->isActionAvailable($member, $document))
			{
				$this->outcomes[$memberId] = $this->hasTargetState($member, $document);

				continue;
			}

			$this->allowedItems[$memberId] = ['member' => $member, 'document' => $document];
		}
	}

	/**
	 * Every item of a branch is processed by a single call, so a chunk costs one call per branch it has
	 * items for instead of one call per item.
	 */
	private function applyBranchOutcomes(): void
	{
		foreach ($this->groupItemsByBranch() as $branch => $items)
		{
			if ($items === [])
			{
				continue;
			}

			// an item the branch said nothing about keeps the outcome it was classified with, a failure
			foreach ($this->callBranch($branch, $items) as $memberId)
			{
				if (isset($this->outcomes[$memberId]))
				{
					$this->outcomes[$memberId] = true;
				}
			}
		}
	}

	/**
	 * @return list<int>
	 */
	private function collectSucceededMemberIds(): array
	{
		return array_keys(array_filter($this->outcomes));
	}

	/**
	 * @return list<array{memberId: int, title: ?string}>
	 */
	private function collectFailedItems(): array
	{
		$failedItems = [];
		foreach ($this->outcomes as $memberId => $isSucceeded)
		{
			if (!$isSucceeded)
			{
				$failedItems[] = $this->createFailedItem($memberId, $this->titles[$memberId]);
			}
		}

		return $failedItems;
	}

	/**
	 * @param list<mixed> $memberIds
	 * @return list<int>
	 */
	private function normalizeMemberIds(array $memberIds): array
	{
		$result = [];
		foreach ($memberIds as $memberId)
		{
			if (is_int($memberId))
			{
				$normalizedId = $memberId;
			}
			elseif (is_string($memberId) && preg_match('/^\d+\z/', $memberId) === 1)
			{
				$normalizedId = (int)$memberId;
			}
			else
			{
				continue;
			}

			if ($normalizedId > 0)
			{
				$result[$normalizedId] = $normalizedId;
			}
		}

		return array_values($result);
	}

	/**
	 * @param list<int> $memberIds
	 */
	private function isCursorValid(array $memberIds, int $totalItems): bool
	{
		if ($this->processedItems < 0 || $this->processedItems > $totalItems)
		{
			return false;
		}

		if ($this->processedItems === 0)
		{
			return true;
		}

		return $this->lastProcessedId === $memberIds[$this->processedItems - 1];
	}

	private function isActionAvailable(Member $member, Document $document): bool
	{
		if ($document->status !== DocumentStatus::SIGNING || !MemberStatus::isReadyForSigning($member->status))
		{
			return false;
		}

		$action = $this->actionStatusService->getActionStatus($document, $member, $this->currentUserId);

		return in_array(
			$this->action,
			$this->actionStatusService->getAvailableBulkActions($action, $document->status),
			true,
		);
	}

	/**
	 * @return array<string, list<array{member: Member, document: Document}>>
	 */
	private function groupItemsByBranch(): array
	{
		$groups = [
			self::BRANCH_ACCEPT => [],
			self::BRANCH_REFUSE => [],
			self::BRANCH_STOP => [],
		];

		foreach ($this->allowedItems as $item)
		{
			$groups[$this->branchOf($item['member'], $item['document'])][] = $item;
		}

		return $groups;
	}

	private function branchOf(Member $member, Document $document): string
	{
		return match ($this->action)
		{
			BulkAction::APPROVE => self::BRANCH_ACCEPT,
			BulkAction::REJECT => $this->mobileService->isRejectStoppingDocument($member, $document)
				? self::BRANCH_STOP
				: self::BRANCH_REFUSE,
		};
	}

	/**
	 * @param list<array{member: Member, document: Document}> $items
	 * @return list<int>
	 */
	private function callBranch(string $branch, array $items): array
	{
		$result = match ($branch)
		{
			self::BRANCH_ACCEPT => $this->mobileService->acceptReviewBatchByMembers($items),
			self::BRANCH_REFUSE => $this->mobileService->refuseSigningBatchByMembers($items),
			self::BRANCH_STOP => $this->mobileService->stopSigningBatchByMembers($items, $this->currentUserId),
		};

		// the reasons themselves stay here - the list of unprocessed documents carries no causes - but the
		// fact of a refused request is kept: it separates «not processed» from «nothing worked»
		if (!$result->isSuccess())
		{
			$this->branchRequestRejected = true;
		}

		return $result->getData()[MobileService::SUCCESSFUL_MEMBER_IDS] ?? [];
	}

	/**
	 * Reject by reviewer or assignee stops the whole document and leaves member status untouched, so a
	 * stop made by the current user is the only trace a repeated step can rely on. An empty initiator is
	 * not read as own: a document expired by its deadline, stopped by the workflow of the service or
	 * stopped before the column existed carries no initiator either, and counting those as done by this
	 * user would report a reject nobody made. The price is a document closed by the `already_done`
	 * outcome, whose trace this run takes back: a repeated step reports it as unprocessed, which sends
	 * the operator to look at the document instead of telling them a lie about their own action.
	 */
	private function hasTargetState(Member $member, Document $document): bool
	{
		return match ($this->action)
		{
			BulkAction::APPROVE => $member->status === MemberStatus::DONE,
			BulkAction::REJECT => $member->status === MemberStatus::REFUSED
				|| (
					$document->status === DocumentStatus::STOPPED
					&& $document->stoppedById === $this->currentUserId
				),
		};
	}

	/**
	 * @return array{memberId: int, title: ?string}
	 */
	private function createFailedItem(int $memberId, ?string $title = null): array
	{
		return [
			'memberId' => $memberId,
			'title' => $title,
		];
	}

	/**
	 * @param list<int> $succeededMemberIds
	 * @param list<array{memberId: int, title: ?string}> $failedItems
	 */
	private function createResponseData(
		string $status,
		int $processedItems,
		int $totalItems,
		int $lastProcessedId,
		array $succeededMemberIds,
		array $failedItems,
		int $sourceItems,
	): array
	{
		$limitApplied = $sourceItems > self::LIMIT;

		return [
			'STATUS' => $status,
			'PROCESSED_ITEMS' => $processedItems,
			'TOTAL_ITEMS' => $totalItems,
			'LAST_PROCESSED_ID' => $lastProcessedId,
			'SUCCEEDED_MEMBER_IDS' => array_values(array_unique($succeededMemberIds)),
			'FAILED_ITEMS' => $failedItems,
			'SOURCE_ITEMS' => $sourceItems,
			'LIMIT_APPLIED' => $limitApplied,
			'WARNING' => $limitApplied && $this->processedItems === 0
				? str_replace(
					['#SOURCE#', '#LIMIT#'],
					[(string)$sourceItems, (string)self::LIMIT],
					$this->limitWarning,
				)
				: '',
		];
	}

	private function createErrorResult(string $message, string $code): Result
	{
		return (new Result())->addError(new Error($message, $code));
	}
}
