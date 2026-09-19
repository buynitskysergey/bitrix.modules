<?php

namespace Bitrix\Sign\Service;

use Bitrix\Main\Context;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Web\Uri;
use Bitrix\Sign\Item\Api\Batch\ItemResult;
use Bitrix\Sign\Item\Api\Mobile\Confirmation\AcceptRequest;
use Bitrix\Sign\Item\Api\Mobile\Confirmation\PostponeRequest;
use Bitrix\Sign\Item\Api\Mobile\Signing\ExternalUrlRequest;
use Bitrix\Sign\Item\Api\Mobile\Signing\RefuseBatchRequest;
use Bitrix\Sign\Item\Api\Mobile\Signing\RefuseRequest;
use Bitrix\Sign\Item\Api\Mobile\Signing\ReviewBatchRequest;
use Bitrix\Sign\Item\Api\Mobile\Signing\ReviewRequest;
use Bitrix\Sign\Item\Api\Mobile\Signing\SignRequest;
use Bitrix\Sign\Item\Document;
use Bitrix\Sign\Item\Member;
use Bitrix\Sign\Item\Mobile\Link;
use Bitrix\Sign\Main\Application;
use Bitrix\Sign\Operation\ChangeMemberStatus;
use Bitrix\Sign\Operation\SigningStop;
use Bitrix\Sign\Operation\SigningStopBatch;
use Bitrix\Sign\Operation\SyncMemberStatus;
use Bitrix\Sign\Result\Service\ExternalSigningUrlResult;
use Bitrix\Sign\Service\Result\Mobile\LinkResult;
use Bitrix\Sign\Type\Member\Role;
use Bitrix\Sign\Type\MemberStatus;
use Bitrix\Sign\Type\ProviderCode;
use Bitrix\SignMobile;
use Bitrix\Sign\Type;

class MobileService
{
	public const SUCCESSFUL_MEMBER_IDS = 'successfulMemberIds';

	private bool $darkMode = false;
	private readonly Sign\DocumentService $documentService;
	private readonly Sign\MemberService $memberService;
	private readonly Sign\Document\ProviderCodeService $providerCodeService;
	private readonly Api\MobileService $mobileService;

	public function __construct()
	{
		$container = Container::instance();
		$this->documentService = $container->getDocumentService();
		$this->memberService = $container->getMemberService();
		$this->providerCodeService = $container->getProviderCodeService();
		$this->mobileService = $container->getApiMobileService();
	}

	public function setDarkMode(bool $isDark): self
	{
		$this->darkMode = $isDark;
		return $this;
	}

	public function getLinkForSigning(int $memberId): LinkResult
	{
		$member = $this->memberService->getById($memberId);

		if (!$member)
		{
			return (new LinkResult())->addError(new Error('not ready for signing'));
		}

		if ($member->status === MemberStatus::DONE)
		{
			return $this->getLinkForFinishedSigning($member);
		}

		$document = $this->documentService->getById($member->documentId);

		if (!$document)
		{
			return (new LinkResult())->addError(new Error('not ready for signing'));
		}

		$result = $this->providerCodeService->loadByDocument($document);
		if (!$result->isSuccess())
		{
			return (new LinkResult())->addErrors($result->getErrors());
		}

		if (
			$document->providerCode === ProviderCode::GOS_KEY
			&& !MemberStatus::isFinishForSigning($member->status)
		)
		{
			(new SyncMemberStatus($member, $document))->launch();
		}

		$result = $this->memberService->getLinkForSigning($member);

		if (!$result->isSuccess())
		{
			return (new LinkResult())->addErrors($result->getErrors());
		}

		$url = $this->getUrlForSigning($result);

		if (!$url)
		{
			return (new LinkResult())->addError(new Error('not ready for signing'));
		}

		$url = $this->addMobileUrlParams($url);

		$link = new Link(
			url: $url,
			documentTitle: $document->title,
			memberId: $member->id,
			role: $member->role,
			status: $member->status,
			documentStatus: $document->status,
			providerCode: $document->providerCode,
			readyForDownload: false,
			initiatedByType: $document->initiatedByType,
		);

		if (
			$document->providerCode === ProviderCode::GOS_KEY
			&& $member->status === MemberStatus::READY
			&& $member->role === Role::ASSIGNEE
			&& $this->memberService->countWaitingSigners($document->id) === 0
		)
		{
			$link->setGoskeyAssigneeAlmostDone();
		}

		return (new LinkResult())->setLink($link);
	}

	private function getLinkForFinishedSigning(Member $member): LinkResult
	{
		$memberService = $this->memberService;
		$result = $memberService->getLinkForSignedFile($member);

		// may be signed, but not ready for download
		$url = null;
		if ($result->isSuccess())
		{
			$url = $this->getUrlForSignedFile($result);
			$url = $this->addMobileUrlParams($url);
		}

		$document = $this->documentService->getById($member->documentId);

		if (!$document)
		{
			return (new LinkResult())->addError(new Error('not ready for signing'));
		}
		$result = $this->providerCodeService->loadByDocument($document);
		if (!$result->isSuccess())
		{
			return (new LinkResult())->addErrors($result->getErrors());
		}

		return (new LinkResult())->setLink(
			new Link(
				url: $url,
				documentTitle: $document->title,
				memberId: $member->id,
				role: $member->role,
				status: $member->status,
				documentStatus: $document->status,
				providerCode: $document->providerCode,
				readyForDownload: $url !== null,
				initiatedByType: $document->initiatedByType,
			),
		);
	}

	private function addMobileUrlParams(string $url): string
	{
		$uri = new Uri($url);
		$uri->addParams(['mobile' => 1]);
		$uri->addParams(['mobileDark' => $this->darkMode ? 1 : 0]);
		return $uri->getUri();
	}

	public function getNextSigningIfExists(int $userId): LinkResult
	{
		$member = Container::instance()
			->getSignMemberUserService()
			->getMemberForSigning($userId, [Role::SIGNER, Role::REVIEWER])
		;

		if ($member)
		{
			return $this->getLinkForSigning($member->id);
		}

		return new LinkResult();
	}

	public function sendSignConfirmationEvent(Member $member): Result
	{
		if (!\Bitrix\Main\Loader::includeModule('signmobile'))
		{
			return (new Result())->addError(new Error('signmobile load error'));
		}

		$result = Container::instance()
			->getMobileService()
			->getLinkForSigning($member->id)
		;

		if ($result->isSuccess() && $link = $result->getLink())
		{
			$userId = $this->memberService->getUserIdForMember($member);

			$service = SignMobile\Service\Container::instance()->getEventService();
			return $service->sendSignConfirmation($userId, $link);
		}

		return (new Result())->addError(new Error('signing error'));
	}

	public function acceptSigning(int $memberId): Result
	{
		$member = Container::instance()->getMemberService()->getById($memberId);
		$resultWithError = (new Result())->addError(new Error('sign error'));

		if (!$member)
		{
			return $resultWithError;
		}

		// only signers can sign on mobile
		if ($member->role !== Role::SIGNER)
		{
			return $resultWithError;
		}

		$document = Container::instance()->getDocumentService()->getById($member->documentId);

		if (!$document)
		{
			return $resultWithError;
		}

		$response = Container::instance()->getApiMobileService()
			->acceptSigning(
				new SignRequest(documentUid: $document->uid, memberUid: $member->uid),
			)
		;

		if ($response->isSuccess())
		{
			$updateResult = (new ChangeMemberStatus($member, $document, MemberStatus::DONE))->launch();

			if (!$updateResult->isSuccess())
			{
				return $updateResult;
			}
		}

		return $response->createResult();
	}

	public function acceptReview(int $memberId): Result
	{
		$member = $this->memberService->getById($memberId);

		if (!$member)
		{
			return (new Result())->addError(new Error('unknown member id'));
		}

		if ($member->role !== Role::REVIEWER)
		{
			return (new Result())->addError(new Error('wrong member role'));
		}

		$document = $this->documentService->getById($member->documentId);
		if (!$document)
		{
			return (new Result())->addError(new Error('unknown document id'));
		}

		return $this->acceptReviewByMember($member, $document);
	}

	public function acceptReviewByMember(Member $member, Document $document): Result
	{
		if ($member->role !== Role::REVIEWER)
		{
			return (new Result())->addError(new Error('wrong member role'));
		}

		if ($document->status !== Type\DocumentStatus::SIGNING || !MemberStatus::isReadyForSigning($member->status))
		{
			return (new Result())->addError(new Error('wrong document or member status'));
		}

		$response = $this->mobileService
			->acceptReview(
				new ReviewRequest($document->uid, $member->uid),
			)
		;

		if ($response->isSuccess())
		{
			$updateResult = (new ChangeMemberStatus($member, $document, MemberStatus::DONE))->launch();

			if (!$updateResult->isSuccess())
			{
				return $updateResult;
			}
		}

		return $response->createResult();
	}

	/**
	 * Accepts the review of a group of documents with a single service call; acceptReviewByMember stays
	 * the way of a single action.
	 *
	 * @param list<array{member: Member, document: Document}> $items members come partially hydrated:
	 *   `name` and `companyName` stay `null` even when the entity has a name, because the bulk caller reads
	 *   them by `MemberRepository::listByIds($ids, loadEntityNames: false)`. No branch of this path may read
	 *   those properties.
	 * @return Result data holds `successfulMemberIds`: a member absent from it is a failure of this run
	 */
	public function acceptReviewBatchByMembers(array $items): Result
	{
		$groupItems = $this->filterBatchItems($items, $this->isReviewAcceptable(...));
		if ($groupItems === [])
		{
			return $this->createBatchResult([]);
		}

		$response = $this->mobileService
			->acceptReviewBatch(
				new ReviewBatchRequest($this->createBatchRequestItems($groupItems)),
			)
		;

		if (!$response->isSuccess())
		{
			return $this->createBatchResult([])->addErrors($response->getErrors());
		}

		return $this->createBatchResult(
			$this->applyMemberOutcomes($groupItems, $response->results, MemberStatus::DONE),
		);
	}

	public function rejectSigning(int $memberId): Result
	{
		$member = $this->memberService->getById($memberId);
		$resultWithRejectError = (new Result())->addError(new Error('reject error'));

		if (!$member)
		{
			return $resultWithRejectError;
		}

		if ($member->role !== Role::SIGNER && $member->role !== Role::REVIEWER)
		{
			return $resultWithRejectError;
		}

		$document = $this->documentService->getById($member->documentId);

		if (!$document)
		{
			return $resultWithRejectError;
		}

		return $this->rejectSigningByMember($member, $document, $member->entityId);
	}

	/**
	 * A stop writes its initiator into `stoppedById`, by which the service callback tells who stopped
	 * the document, so the user id comes from the caller and from nowhere else: `$member->entityId`
	 * holds the id of a CRM company for a member of the company side, not the id of a user.
	 */
	public function rejectSigningByMember(
		Member $member,
		Document $document,
		?int $actorUserId,
	): Result
	{
		$resultWithRejectError = (new Result())->addError(new Error('reject error'));

		if ($member->role !== Role::SIGNER && $member->role !== Role::REVIEWER)
		{
			return $resultWithRejectError;
		}

		if ($this->isRejectStoppingDocument($member, $document))
		{
			if ($actorUserId === null || $actorUserId < 1)
			{
				return $resultWithRejectError;
			}

			return SigningStop::createByDocument($document, $actorUserId)->launch();
		}

		$response = $this->mobileService
			->refuseSigning(
				new RefuseRequest(documentUid: $document->uid, memberUid: $member->uid),
			)
		;

		if ($response->isSuccess())
		{
			$result = (new ChangeMemberStatus($member, $document, MemberStatus::REFUSED))->launch();

			if (!$result->isSuccess())
			{
				return $result;
			}
		}

		return $response->createResult();
	}

	/**
	 * A reject by a reviewer or an assignee, and any reject of a document initiated by an employee, stop
	 * the whole document instead of refusing a single signing.
	 */
	public function isRejectStoppingDocument(Member $member, Document $document): bool
	{
		return $document->initiatedByType === Type\Document\InitiatedByType::EMPLOYEE
			|| $member->role === Role::REVIEWER
			|| $member->role === Role::ASSIGNEE
		;
	}

	/**
	 * Refuses the signing of a group of documents with a single service call; only a signer of a document
	 * initiated by the company refuses it that way, the rest of the rejects stop the document.
	 *
	 * @param list<array{member: Member, document: Document}> $items members come partially hydrated:
	 *   `name` and `companyName` stay `null` even when the entity has a name, because the bulk caller reads
	 *   them by `MemberRepository::listByIds($ids, loadEntityNames: false)`. No branch of this path may read
	 *   those properties.
	 * @return Result data holds `successfulMemberIds`: a member absent from it is a failure of this run
	 */
	public function refuseSigningBatchByMembers(array $items): Result
	{
		$groupItems = $this->filterBatchItems($items, $this->isRefuseAcceptable(...));
		if ($groupItems === [])
		{
			return $this->createBatchResult([]);
		}

		$response = $this->mobileService
			->refuseSigningBatch(
				new RefuseBatchRequest($this->createBatchRequestItems($groupItems)),
			)
		;

		if (!$response->isSuccess())
		{
			return $this->createBatchResult([])->addErrors($response->getErrors());
		}

		return $this->createBatchResult(
			$this->applyMemberOutcomes($groupItems, $response->results, MemberStatus::REFUSED),
		);
	}

	/**
	 * Stops the documents of a group with a single service call. Member statuses stay untouched here, the
	 * same way a single reject by a reviewer or an assignee leaves them to the document stop.
	 *
	 * @param list<array{member: Member, document: Document}> $items members come partially hydrated:
	 *   `name` and `companyName` stay `null` even when the entity has a name, because the bulk caller reads
	 *   them by `MemberRepository::listByIds($ids, loadEntityNames: false)`. No branch of this path may read
	 *   those properties.
	 * @param int $actorUserId initiator of the stop
	 * @return Result data holds `successfulMemberIds`: a member absent from it is a failure of this run
	 */
	public function stopSigningBatchByMembers(array $items, int $actorUserId): Result
	{
		if ($actorUserId < 1)
		{
			return $this->createBatchResult([])->addError(new Error('reject error'));
		}

		$groupItems = $this->filterBatchItems($items, $this->isStopAcceptable(...));
		if ($groupItems === [])
		{
			return $this->createBatchResult([]);
		}

		$result = (new SigningStopBatch($this->collectBatchDocuments($groupItems), $actorUserId))->launch();
		$successfulDocumentUids = $result->getData()[SigningStopBatch::SUCCESSFUL_DOCUMENT_UIDS] ?? [];

		return $this
			->createBatchResult($this->collectMemberIdsByDocumentUids($groupItems, $successfulDocumentUids))
			->addErrors($result->getErrors())
		;
	}

	public function acceptConfirmation(int $memberId): Result
	{
		$member = $this->memberService->getById($memberId);

		if (!$member)
		{
			return (new Result())->addError(new Error('confirm error'));
		}

		$document = $this->documentService->getById($member->documentId);

		if (!$document)
		{
			return (new Result())->addError(new Error('confirm error'));
		}

		$response = Container::instance()->getApiMobileService()->acceptConfirmation(
			new AcceptRequest(documentUid: $document->uid, memberUid: $member->uid),
		);

		if (!$response->isSuccess())
		{
			return $response->createResult();
		}

		return (new ChangeMemberStatus($member, $document, MemberStatus::DONE))->launch();
	}

	public function postponeConfirmation(int $memberId): Result
	{
		$member = $this->memberService->getById($memberId);

		if ($member)
		{
			$document = $this->documentService->getById($member->documentId);

			if ($document)
			{
				$response = Container::instance()->getApiMobileService()->postponeConfirmation(
					new PostponeRequest(documentUid: $document->uid, memberUid: $member->uid),
				);

				return (new Result())->addErrors(
					$response->getErrors(),
				);
			}
		}

		return (new Result())->addError(new Error('reject error'));
	}

	public function checkAccessToSigning(int $memberId, int $userId): Result
	{
		$member = $this->memberService->getById($memberId);

		if (
			!$member
			|| !Container::instance()->getSignMemberUserService()->checkAccessToMember($member, $userId))
		{
			return (new Result())->addError(new Error(
				'access denied',
				'ACCESS_DENIED',
			));
		}

		return new Result();
	}

	public function getExternalSigningUrl(int $memberId): Result|ExternalSigningUrlResult
	{
		$member = $this->memberService->getById($memberId);

		if (!$member)
		{
			return (new Result())->addError(new Error('member not found'));
		}

		$document = $this->documentService->getById($member->documentId);

		if (!$document)
		{
			return (new Result())->addError(new Error('document not found'));
		}

		$response = Container::instance()
			->getApiMobileService()
			->getExternalSigningUrl(
				new ExternalUrlRequest($document->uid, $member->uid)
			)
		;

		if (!$response->isSuccess())
		{
			return $response->createResult();
		}

		return new ExternalSigningUrlResult($response->url);
	}

	private function getUrlForSignedFile(Result $result): ?string
	{
		$path = $result->getData()['url'] ?? null;

		if ($path)
		{
			$scheme = Context::getCurrent()?->getRequest()->isHttps() === false
				? 'http://'
				: 'https://'
			;
			return
				$scheme
				. Application::getServer()->getHttpHost()
				. $path
			;
		}

		return null;
	}

	private function getUrlForSigning(Result $result): ?string
	{
		return $result->getData()['uri'] ?? null;
	}

	private function isReviewAcceptable(Member $member, Document $document): bool
	{
		return $member->role === Role::REVIEWER
			&& $document->status === Type\DocumentStatus::SIGNING
			&& MemberStatus::isReadyForSigning($member->status)
		;
	}

	private function isRefuseAcceptable(Member $member, Document $document): bool
	{
		return $member->role === Role::SIGNER && !$this->isRejectStoppingDocument($member, $document);
	}

	private function isStopAcceptable(Member $member, Document $document): bool
	{
		return in_array($member->role, [Role::SIGNER, Role::REVIEWER, Role::ASSIGNEE], true)
			&& $this->isRejectStoppingDocument($member, $document)
		;
	}

	/**
	 * An item the service cannot address, or one the branch does not apply to, is left out of the group:
	 * a batch route rejects the whole group over a single empty uid.
	 *
	 * @param list<array{member: Member, document: Document}> $items
	 * @param callable(Member, Document): bool $isAcceptable
	 * @return list<array{member: Member, document: Document}>
	 */
	private function filterBatchItems(array $items, callable $isAcceptable): array
	{
		$groupItems = [];
		foreach ($items as $item)
		{
			$member = $item['member'];
			$document = $item['document'];
			if (
				$member->id === null
				|| $member->uid === null || $member->uid === ''
				|| $document->uid === null || $document->uid === ''
				|| !$isAcceptable($member, $document)
			)
			{
				continue;
			}

			$groupItems[] = $item;
		}

		return $groupItems;
	}

	/**
	 * @param list<array{member: Member, document: Document}> $items
	 * @return list<array{documentId: string, memberId: string}>
	 */
	private function createBatchRequestItems(array $items): array
	{
		return array_map(
			static fn (array $item): array => [
				'documentId' => (string)$item['document']->uid,
				'memberId' => (string)$item['member']->uid,
			],
			$items,
		);
	}

	/**
	 * @param list<array{member: Member, document: Document}> $items
	 * @param ItemResult[] $results
	 * @return list<int> ids of the members that reached the target state
	 */
	private function applyMemberOutcomes(array $items, array $results, string $status): array
	{
		$resultsByItem = [];
		foreach ($results as $result)
		{
			$resultsByItem[$this->createBatchItemKey($result->documentUid, $result->memberUid)] = $result;
		}

		$successfulMemberIds = [];
		foreach ($items as $item)
		{
			$member = $item['member'];
			$result = $resultsByItem[
				$this->createBatchItemKey((string)$item['document']->uid, (string)$member->uid)
			] ?? null;

			// the service said nothing about the member, so the outcome of the item is not a success either
			if ($result === null || !$result->isSuccess())
			{
				continue;
			}

			// `already_done` means the service holds the member in the target status of the action, so the
			// local status is brought to the same state as after a success. Leaving it to the callback of
			// the earlier call is what keeps the row in progress forever once that callback is lost: a
			// repeated run answers `already_done` again and would never move it either. A member already
			// in the target status needs no change and is a success of the item as it is.
			if (
				$member->status !== $status
				&& !(new ChangeMemberStatus($member, $item['document'], $status))->launch()->isSuccess()
			)
			{
				continue;
			}

			$successfulMemberIds[] = (int)$member->id;
		}

		return $successfulMemberIds;
	}

	/**
	 * An item of the contract is a document/member pair, so a result is only the answer about an
	 * item when both uids match: a member uid alone would let an answer about another document
	 * move the status of this one.
	 */
	private function createBatchItemKey(string $documentUid, ?string $memberUid): string
	{
		return $documentUid . "\0" . (string)$memberUid;
	}

	/**
	 * @param list<array{member: Member, document: Document}> $items
	 * @return list<Document> one entry per document: members of the same document share its stop
	 */
	private function collectBatchDocuments(array $items): array
	{
		$documentsByUid = [];
		foreach ($items as $item)
		{
			$documentsByUid[(string)$item['document']->uid] = $item['document'];
		}

		return array_values($documentsByUid);
	}

	/**
	 * @param list<array{member: Member, document: Document}> $items
	 * @param list<string> $documentUids
	 * @return list<int>
	 */
	private function collectMemberIdsByDocumentUids(array $items, array $documentUids): array
	{
		$successfulMemberIds = [];
		foreach ($items as $item)
		{
			if (in_array((string)$item['document']->uid, $documentUids, true))
			{
				$successfulMemberIds[] = (int)$item['member']->id;
			}
		}

		return $successfulMemberIds;
	}

	/**
	 * @param list<int> $successfulMemberIds
	 */
	private function createBatchResult(array $successfulMemberIds): Result
	{
		return (new Result())->setData([self::SUCCESSFUL_MEMBER_IDS => $successfulMemberIds]);
	}
}
