<?php

namespace Bitrix\Sign\Operation;

use Bitrix\Main;
use Bitrix\Main\Type\DateTime;
use Bitrix\Sign\Contract;
use Bitrix\Sign\FeatureResolver;
use Bitrix\Sign\Item;
use Bitrix\Sign\Repository\DocumentRepository;
use Bitrix\Sign\Repository\MemberRepository;
use Bitrix\Sign\Result\Repository\Member\AnnulmentSwitchResult;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Type\DocumentScenario;
use Bitrix\Sign\Type\Member\Role;
use Bitrix\Sign\Type\MemberStatus;

/**
 * Reversible annulment mark on a completed signer member, on top of the `done`
 * member status.
 *
 * The `ANNULLED` flag on the member is the source of truth. The operation is
 * orthogonal to signing mechanics: it never changes the member status (SIGNED)
 * and does not touch status operations (ChangeDocumentStatus/ChangeMemberStatus).
 * Only a member with the SIGNER role in the DONE status can be annulled.
 */
class AnnulDocument implements Contract\Operation
{
	public const ERROR_MEMBER_NOT_SIGNED = 'SIGN_ANNUL_MEMBER_NOT_SIGNED';
	public const ERROR_UPDATE_FAILED = 'SIGN_ANNUL_MEMBER_UPDATE_FAILED';
	public const ERROR_DOCUMENT_NOT_B2E = 'SIGN_ANNUL_DOCUMENT_NOT_B2E';

	private readonly MemberRepository $memberRepository;
	private readonly DocumentRepository $documentRepository;

	/**
	 * @param bool $withSideEffects Whether the operation emits the per-record side
	 *        effects (timeline event, HR-bot card) itself. Single-member paths leave
	 *        it on; batch paths pass false because they own the side effects of the
	 *        whole action: one timeline event and one deferred card fan-out per
	 *        document instead of one per record.
	 */
	public function __construct(
		private readonly Item\Member $member,
		private readonly bool $annul,
		private readonly ?int $userId = null,
		private readonly bool $withSideEffects = true,
		?MemberRepository $memberRepository = null,
		?DocumentRepository $documentRepository = null,
	)
	{
		$this->memberRepository = $memberRepository ?? Container::instance()->getMemberRepository();
		$this->documentRepository = $documentRepository ?? Container::instance()->getDocumentRepository();
	}

	public function launch(): Main\Result
	{
		$result = new Main\Result();

		// Always work off a fresh, uncached member: the flag is the source of
		// truth and must be evaluated against the latest persisted state
		// (getById does not cache, unlike getByUid).
		$member = $this->member->id !== null
			? $this->memberRepository->getById($this->member->id)
			: null
		;
		if ($member === null)
		{
			return $result->addError(
				new Main\Error('Member not found', self::ERROR_MEMBER_NOT_SIGNED),
			);
		}

		// The predicate is evaluated on the persisted role and status, never on
		// displayed grid fields: only a completed signer can be annulled.
		if ($member->role !== Role::SIGNER || $member->status !== MemberStatus::DONE)
		{
			return $result->addError(
				new Main\Error('Only a completed signer member can be annulled', self::ERROR_MEMBER_NOT_SIGNED),
			);
		}

		// Idempotency: the flag is the source of truth. When the member is already
		// in the target state, do nothing and cause no side effects. This is the cheap
		// filter for the obvious no-op only - the write below is what actually decides
		// whether a transition happened.
		if ($member->annulled === $this->annul)
		{
			return $result->setData($this->buildData($member, false));
		}

		// The annulment mark is a B2E/KEDO-only feature. Resolve the owning
		// document once, up front, and reject any non-B2E (or unresolved) document
		// before writing (defense-in-depth): a single guard here covers every
		// entry point (web V1 + mobile), mirroring the null/non-B2E rejection the
		// web actions already perform. The instance is reused for the post-save
		// side effects, so the document is read at most once per launch.
		$document = $member->documentId !== null
			? $this->documentRepository->getById($member->documentId)
			: null
		;
		if ($document === null || !DocumentScenario::isB2EScenario($document->scenario))
		{
			return $result->addError(
				new Main\Error('Only b2e documents can be annulled', self::ERROR_DOCUMENT_NOT_B2E),
			);
		}

		// The write carries the opposite state as its own condition, so it switches the
		// mark only while the record still holds that state and tells the caller whether
		// it did. The transition is what it reports, and the side effects follow from
		// that report rather than from the check above.
		$switchResult = $this->memberRepository->annulById((int)$member->id, $this->annul, $this->userId);
		if (!$switchResult->isSuccess())
		{
			return $result->addError(new Main\Error('Can not update member', self::ERROR_UPDATE_FAILED));
		}

		/** @var AnnulmentSwitchResult $switchResult */
		if ($switchResult->switchedCount === 0)
		{
			// A parallel request going the same way was there first: the record already
			// carries the target state, switched and attributed by that request. This one
			// changed nothing, so it names no author and announces nothing.
			$member->annulled = $this->annul;

			return $result->setData($this->buildData($member, false));
		}

		// Mirrors what the write stored: the mark plus the author and moment of this
		// very switch, whichever direction it went.
		$member->annulled = $this->annul;
		$member->annulledById = $this->userId;
		$member->dateAnnulled = new DateTime();

		// Single-member paths emit the per-record side effects here, post-save and
		// only on a real transition. Batch paths pass withSideEffects=false and emit
		// their own, aggregated per document.
		if ($this->withSideEffects)
		{
			$this->emitAnnulmentTimelineEvent($member, $document);
			$this->sendAnnulmentNotification($member, $document);
		}

		return $result->setData($this->buildData($member, true));
	}

	/**
	 * Best-effort annulment timeline event on the linked SMART_B2E card.
	 *
	 * Emitted post-save and only on an actual state change (no-op annulments
	 * return earlier). The annulment flag is the source of truth: a failure of
	 * the crm timeline must never roll back the persisted flag, so any error is
	 * swallowed and logged.
	 *
	 * The feature flag is re-checked here on purpose: every current caller is
	 * already behind the same gate, but the operation is callable on its own and
	 * must not write timeline entries for a feature that is switched off.
	 */
	private function emitAnnulmentTimelineEvent(Item\Member $member, Item\Document $document): void
	{
		// No actor to attribute the change to: skip the actor-attributed timeline
		// event rather than crediting a fabricated user 0.
		$actorId = $this->userId;
		if ($actorId === null)
		{
			return;
		}

		try
		{
			if (!FeatureResolver::instance()->released('kedoDocumentAnnul'))
			{
				return;
			}

			if (!Main\Loader::includeModule('crm'))
			{
				return;
			}

			Container::instance()->getEventHandlerService()->handleDocumentAnnulled(
				$document,
				$actorId,
				$this->annul,
				$member,
			);
		}
		catch (\Throwable $e)
		{
			Container::instance()->getLogger('Operation')->error(
				'Failed to emit annulment timeline event for member {memberUid}: {errorsText}',
				[
					'memberUid' => $member->uid,
					'errorsText' => $e->getMessage(),
				],
			);
		}
	}

	/**
	 * Best-effort inline delivery of the annulment/un-annulment HR-bot card.
	 *
	 * Sent post-save and only on an actual state change (no-op annulments return
	 * earlier). The annulment flag is the source of truth: a chat delivery failure
	 * must never roll back the persisted flag, so any error is swallowed and
	 * logged, mirroring emitAnnulmentTimelineEvent.
	 */
	private function sendAnnulmentNotification(Item\Member $member, Item\Document $document): void
	{
		// No actor to attribute the change to: skip the actor-attributed
		// notification rather than crediting a fabricated user 0.
		$actorId = $this->userId;
		if ($actorId === null)
		{
			return;
		}

		try
		{
			Container::instance()->getHrBotMessageService()->handleMemberAnnulled(
				$document,
				$member,
				$this->annul,
				$actorId,
			);
		}
		catch (\Throwable $e)
		{
			Container::instance()->getLogger('Operation')->error(
				'Failed to send annulment notification for member {memberUid}: {errorsText}',
				[
					'memberUid' => $member->uid,
					'errorsText' => $e->getMessage(),
				],
			);
		}
	}

	/**
	 * @return array{uid: string|null, isAnnulled: bool, changed: bool}
	 */
	private function buildData(Item\Member $member, bool $changed): array
	{
		return [
			'uid' => $member->uid,
			'isAnnulled' => $member->annulled,
			'changed' => $changed,
		];
	}
}
