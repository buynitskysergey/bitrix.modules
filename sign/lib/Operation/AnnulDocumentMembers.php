<?php

namespace Bitrix\Sign\Operation;

use Bitrix\Main;
use Bitrix\Sign\Contract;
use Bitrix\Sign\Item;
use Bitrix\Sign\Repository\MemberRepository;
use Bitrix\Sign\Result\Repository\Member\AnnulmentSwitchResult;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Type\DocumentScenario;
use Bitrix\Sign\Type\Member\Role;
use Bitrix\Sign\Type\MemberStatus;

/**
 * Applies the annulment mark to every completed signer of one document, addressing
 * the document as a whole (used by the CRM card).
 *
 * The switchable set (SIGNER role, DONE status, mark still opposite to the target) is
 * resolved by the database rather than filtered in PHP, and the mark is switched by a
 * single batch update, so the cost of the write does not grow with the number of
 * signers. The write names the records it really switched, and the records another
 * request switched in the meantime are left to that request: they are neither counted
 * nor announced here.
 *
 * Access is not checked here: the caller owns the permission decision, exactly as it
 * does for the single-member operation.
 */
final class AnnulDocumentMembers implements Contract\Operation
{
	public const ERROR_DOCUMENT_NOT_B2E = 'SIGN_ANNUL_DOCUMENT_NOT_B2E';
	public const ERROR_UPDATE_FAILED = 'SIGN_ANNUL_MEMBER_UPDATE_FAILED';

	private readonly MemberRepository $memberRepository;

	public function __construct(
		private readonly Item\Document $document,
		private readonly bool $annul,
		private readonly ?int $userId = null,
		?MemberRepository $memberRepository = null,
	)
	{
		$this->memberRepository = $memberRepository ?? Container::instance()->getMemberRepository();
	}

	/**
	 * @return Main\Result data: array{changed: int, unchanged: int, skipped: int}
	 */
	public function launch(): Main\Result
	{
		$result = new Main\Result();

		$documentId = (int)$this->document->id;
		if ($documentId <= 0 || !DocumentScenario::isB2EScenario($this->document->scenario))
		{
			return $result->addError(
				new Main\Error('Only b2e documents can be annulled', self::ERROR_DOCUMENT_NOT_B2E),
			);
		}

		// Counted, not hydrated: the whole eligible set only feeds the `unchanged`
		// counter, while the records themselves are needed for the switchable ones.
		$eligibleCount = $this->memberRepository->countMembersByDocumentIdAndRoleAndStatus(
			$documentId,
			[MemberStatus::DONE],
			Role::SIGNER,
		);

		$switchable = $this->memberRepository->listAnnulmentNotificationTargets(
			$documentId,
			Role::SIGNER,
			MemberStatus::DONE,
			annulled: !$this->annul,
		);
		if ($switchable->isEmpty())
		{
			return $result->setData($this->buildData(0, $eligibleCount));
		}

		$switchResult = $this->memberRepository->annulByIds(
			$switchable->getIds(),
			$this->annul,
			$this->userId,
		);
		if (!$switchResult->isSuccess())
		{
			return $result->addError(new Main\Error('Can not update members', self::ERROR_UPDATE_FAILED));
		}

		/** @var AnnulmentSwitchResult $switchResult */
		$switched = $this->keepSwitched($switchable, $switchResult);

		// Nothing was switched: every resolved record had already been switched by a
		// parallel request, which owns the announcement of its own action.
		if ($switched->isEmpty())
		{
			return $result->setData($this->buildData(0, $eligibleCount));
		}

		$this->emitSideEffects($switched);

		return $result->setData($this->buildData($switched->count(), $eligibleCount));
	}

	/**
	 * The records of the resolved set the write really switched.
	 *
	 * The set is resolved by one statement and switched by another, so a parallel
	 * request can switch part of it in between; those records belong to that request,
	 * which names its own author and announces them itself. Addressing the side effects
	 * by the resolved set instead would send its recipients a second card crediting
	 * this request for a switch stored under another author.
	 */
	private function keepSwitched(
		Item\MemberCollection $switchable,
		AnnulmentSwitchResult $switchResult,
	): Item\MemberCollection
	{
		$switchedIds = array_flip($switchResult->switchedIds);

		return $switchable->filter(static fn(Item\Member $member): bool => isset($switchedIds[$member->id]));
	}

	/**
	 * Side effects for the switched records: the timeline entry and the initiator card
	 * count them, and the employee cards go to them, so neither can report a switch
	 * that did not happen or reach a recipient this request did not switch.
	 */
	private function emitSideEffects(Item\MemberCollection $switchedMembers): void
	{
		// No actor to attribute the change to: skip the actor-attributed side effects
		// rather than crediting a fabricated user 0.
		$actorId = $this->userId;
		if ($actorId === null)
		{
			return;
		}

		// One document-level timeline event for the whole action instead of one per
		// record: every record belongs to this one document, so per-record events
		// would reload the same CRM item and push the same activity N times over.
		// It carries the number of changed records, so the entry still tells a
		// partial annulment from a complete one.
		$this->emitAnnulmentTimelineEvent($actorId, $switchedMembers->count());

		$this->scheduleAnnulmentCards($switchedMembers, $actorId);
	}

	/**
	 * The employee card stays per recipient (each has their own chat with the bot),
	 * so the fan-out is deferred to a background job of this request and the caller
	 * does not wait for it.
	 *
	 * Best-effort like the timeline event: the mark is already persisted and is the
	 * source of truth, so a failure to even schedule the delivery must not surface
	 * as an error of a write that succeeded.
	 */
	private function scheduleAnnulmentCards(Item\MemberCollection $switchedMembers, int $actorId): void
	{
		try
		{
			Container::instance()->getHrBotMessageService()->scheduleMembersAnnulled(
				$this->document,
				$switchedMembers,
				$this->annul,
				$actorId,
				$switchedMembers->count(),
			);
		}
		catch (\Throwable $e)
		{
			Container::instance()->getLogger('Operation')->error(
				'Failed to schedule annulment cards for document {documentId}: {errorsText}',
				[
					'documentId' => $this->document->id,
					'errorsText' => $e->getMessage(),
				],
			);
		}
	}

	/**
	 * Best-effort annulment timeline event on the linked SMART_B2E card. The mark is
	 * the source of truth: a crm failure must never roll back the persisted flag, so
	 * any error is swallowed and logged.
	 *
	 * The event carries no member: it reports the document as a whole, which is
	 * exactly what this operation applies, and names the number of changed
	 * signings instead.
	 */
	private function emitAnnulmentTimelineEvent(int $actorId, int $changedCount): void
	{
		try
		{
			if (!Main\Loader::includeModule('crm'))
			{
				return;
			}

			Container::instance()->getEventHandlerService()->handleDocumentAnnulled(
				$this->document,
				$actorId,
				$this->annul,
				null,
				$changedCount,
			);
		}
		catch (\Throwable $e)
		{
			Container::instance()->getLogger('Operation')->error(
				'Failed to emit annulment timeline event for document {documentId}: {errorsText}',
				[
					'documentId' => $this->document->id,
					'errorsText' => $e->getMessage(),
				],
			);
		}
	}

	/**
	 * @return array{changed: int, unchanged: int, skipped: int}
	 */
	private function buildData(int $changed, int $eligibleCount): array
	{
		return [
			'changed' => $changed,
			// The server resolves the eligible records itself, so nothing is skipped by
			// construction; the key is kept for a uniform response shape.
			'unchanged' => max(0, $eligibleCount - $changed),
			'skipped' => 0,
		];
	}
}
