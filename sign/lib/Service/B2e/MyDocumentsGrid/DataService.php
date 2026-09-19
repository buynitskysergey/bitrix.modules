<?php

namespace Bitrix\Sign\Service\B2e\MyDocumentsGrid;

use Bitrix\Sign\Access\DocumentAnnulPermission;
use Bitrix\Sign\FeatureResolver;
use Bitrix\Sign\Item\Member;
use Bitrix\Sign\Item\Document;
use Bitrix\Main\Type\DateTime;
use Bitrix\Sign\Service\Container;
use Bitrix\Sign\Type\Member\Role;
use Bitrix\Sign\Type\MemberStatus;
use Bitrix\Sign\Service\UserService;
use Bitrix\Sign\Type\DocumentStatus;
use Bitrix\Sign\Item\MemberCollection;
use Bitrix\Sign\Item\DocumentCollection;
use Bitrix\Sign\Service\Sign\MemberService;
use Bitrix\Sign\Repository\MemberRepository;
use Bitrix\Sign\Repository\DocumentRepository;
use Bitrix\Sign\Type\Document\InitiatedByType;
use Bitrix\Sign\Service\Sign\DocumentService;
use Bitrix\Sign\Item\MyDocumentsGrid\Row;
use Bitrix\Sign\Item\MyDocumentsGrid\File;
use Bitrix\Sign\Item\MyDocumentsGrid\Grid;
use Bitrix\Sign\Item\MyDocumentsGrid\MyDocumentsFilter;
use Bitrix\Sign\Item\MyDocumentsGrid\RowCollection;
use Bitrix\Sign\Type\MyDocumentsGrid\ActorRole;
use Bitrix\Sign\Type\MyDocumentsGrid\FilterStatus;

class DataService
{
	private readonly MemberRepository $memberRepository;
	private readonly DocumentRepository $documentRepository;
	private readonly MemberService $memberService;
	private readonly DocumentService $documentService;
	private readonly ActionStatusService $actionStatusService;
	private readonly UserService $userService;
	private readonly SignedFileService $signedFileService;

	public function __construct()
	{
		$this->memberRepository = Container::instance()->getMemberRepository();
		$this->documentRepository = Container::instance()->getDocumentRepository();
		$this->memberService = Container::instance()->getMemberService();
		$this->documentService = Container::instance()->getDocumentService();
		$this->actionStatusService = Container::instance()->getActionStatusService();
		$this->userService = Container::instance()->getUserService();
		$this->signedFileService = Container::instance()->getMyDocumentsGridSignedFileService();
	}

	public function getGridData(
		int $limit,
		int $offset,
		int $userId,
		?MyDocumentsFilter $filter = null,
	): Grid
	{
		$userIds = [];
		$rows = new RowCollection();
		// Resolve the annul owner scope once per grid render and reuse per row,
		// but only when the feature is on; otherwise canAnnul stays the DTO
		// default (false) and no permission/department queries are issued.
		$annulPermission = FeatureResolver::instance()->released('kedoDocumentAnnul')
			? DocumentAnnulPermission::forUser($userId)
			: null
		;
		$members = $this->getMembersForCurrentUser(
			$userId,
			$limit,
			$offset,
			$filter,
		);
		$documents = $this->getDocuments($members);
		$secondSideMembersMap = $this->getSecondSideMembersForEmployeeMap($members, $documents, $userId);
		$fromEmployeeRoleMembersMap = $this->getFromEmployeeRoleMembersMap(
			$members,
			$documents,
			$secondSideMembersMap,
			$userId,
		);
		$signedFiles = $this->signedFileService->listByMemberIds(
			$this->collectSignedFileMemberIds($members, $secondSideMembersMap, $fromEmployeeRoleMembersMap),
		);
		$userPresentations = $this->loadUserPresentations(
			$this->collectPageUserIds($members, $documents, $secondSideMembersMap, $userId),
		);
		$documentIdsWithSuccessfulSigners = $this->loadDocumentIdsWithSuccessfulSigners($members, $documents);

		foreach ($members as $myMemberInProcess)
		{
			$document = $documents->getById((int)$myMemberInProcess->documentId);
			if (!$document)
			{
				continue;
			}

			$member = $this->resolveDisplayedMember($myMemberInProcess, $document, $secondSideMembersMap, $userId);

			$fileData = null;
			if ($document->isInitiatedByEmployee() && DocumentStatus::isFinalByDocument($document))
			{
				$fileData = $this->getFromEmployeeFileLink(
					$document,
					$myMemberInProcess,
					$member,
					$signedFiles,
					$fromEmployeeRoleMembersMap,
				);
			}
			else
			{
				$relevantMember = !$document->isInitiatedByEmployee() || $document->status === DocumentStatus::DONE
					? $member
					: $myMemberInProcess
				;
				$fileData = $this->getFileData($relevantMember, $signedFiles);
			}

			$dateSend = $member->dateSend ?? $myMemberInProcess->dateSend;
			$initiatorData = $this->buildMemberData(
				$document,
				$member,
				$userId,
				$userPresentations,
				true,
			);

			$memberData = $this->buildMemberData($document, $member, $userId, $userPresentations);
			if ($this->isDocumentStoppedForEmployee($myMemberInProcess, $document))
			{
				$stoppedUserPresentation = $this->getUserPresentation($userPresentations, $document->stoppedById);
				$memberData = new \Bitrix\Sign\Item\MyDocumentsGrid\Member(
					isCurrentUser: $document->stoppedById === $userId,
					isStopped: true,
					userId: $document->stoppedById,
					fullName: $stoppedUserPresentation['fullName'],
					icon: $stoppedUserPresentation['icon'],
				);
			}

			if (!in_array($memberData->userId, $userIds, true))
			{
				$userIds[] = $memberData->userId;
			}

			if (!in_array($initiatorData->userId, $userIds, true))
			{
				$userIds[] = $initiatorData->userId;
			}

			$isDocumentHasSuccessfulSigners = $document->status === DocumentStatus::STOPPED
				? isset($documentIdsWithSuccessfulSigners[$document->id])
				: null
			;
			$rowDocument = new \Bitrix\Sign\Item\MyDocumentsGrid\Document(
				$document->id,
				$this->documentService->getComposedTitleByDocument($document),
				$document->providerCode,
				$this->getSignDate($myMemberInProcess, $document),
				$dateSend,
				$this->getEditDate($myMemberInProcess),
				$this->getApprovedDate($myMemberInProcess),
				$this->getCancelledDate($myMemberInProcess, $document),
				$document->status,
				$initiatorData,
				$document->initiatedByType,
				$document->stoppedById,
				$isDocumentHasSuccessfulSigners,
				isAnnulled: $myMemberInProcess->annulled,
				canAnnul: $annulPermission?->canAnnulDocumentOwnedBy($document->createdById) ?? false,
			);

			$rows->add(
				new Row(
					$myMemberInProcess->id,
					$rowDocument,
					new \Bitrix\Sign\Item\MyDocumentsGrid\MemberCollection(...[$memberData]),
					$this->buildMemberData(
						$document,
						$myMemberInProcess,
						$userId,
						$userPresentations,
					),
					$fileData,
					$this->actionStatusService->getActionStatus(
						$document,
						$myMemberInProcess,
						$userId,
					),
				)
			);
		}

		return new Grid(
			$rows,
			$this->getTotalCountMembers($userId, $filter),
			$userIds,
		);
	}

	/**
	 * @param array<int, ?File> $signedFiles
	 */
	private function getFileData(?Member $member, array $signedFiles): ?File
	{
		if ($member?->id === null)
		{
			return null;
		}

		return $signedFiles[$member->id] ?? null;
	}

	/**
	 * @psalm-param array<int, array<int, Member>> $secondSideMembersMap
	 * @psalm-param array<int, array<string, Member>> $fromEmployeeRoleMembersMap
	 * @return list<int>
	 */
	private function collectSignedFileMemberIds(
		MemberCollection $members,
		array $secondSideMembersMap,
		array $fromEmployeeRoleMembersMap,
	): array
	{
		$memberIds = [];
		foreach ($members as $member)
		{
			if ($member->id !== null)
			{
				$memberIds[$member->id] = $member->id;
			}
		}

		foreach ($secondSideMembersMap as $secondSideMembers)
		{
			foreach ($secondSideMembers as $secondSideMember)
			{
				if ($secondSideMember->id !== null)
				{
					$memberIds[$secondSideMember->id] = $secondSideMember->id;
				}
			}
		}

		foreach ($fromEmployeeRoleMembersMap as $roleMembers)
		{
			foreach ($roleMembers as $roleMember)
			{
				if ($roleMember->id !== null)
				{
					$memberIds[$roleMember->id] = $roleMember->id;
				}
			}
		}

		return array_values($memberIds);
	}

	/**
	 * @psalm-param array<int, array<int, Member>> $secondSideMembersMap
	 */
	private function resolveDisplayedMember(
		Member $myMemberInProcess,
		Document $document,
		array $secondSideMembersMap,
		int $userId,
	): Member
	{
		if ($this->isSecondSideMemberForEmployee($myMemberInProcess, $document, $userId))
		{
			$secondSideMember = $secondSideMembersMap[$document->id] ?? null;
			if ($secondSideMember != null)
			{
				return $secondSideMember[0];
			}
		}

		return $myMemberInProcess;
	}

	/**
	 * Collects every user shown on the page, so that they are read in a single query.
	 *
	 * @psalm-param array<int, array<int, Member>> $secondSideMembersMap
	 * @return list<int>
	 */
	private function collectPageUserIds(
		MemberCollection $members,
		DocumentCollection $documents,
		array $secondSideMembersMap,
		int $userId,
	): array
	{
		$userIds = [];
		foreach ($members as $myMemberInProcess)
		{
			$document = $documents->getById((int)$myMemberInProcess->documentId);
			if (!$document)
			{
				continue;
			}

			$member = $this->resolveDisplayedMember($myMemberInProcess, $document, $secondSideMembersMap, $userId);
			$rowUserIds = [
				$document->createdById,
				$this->memberService->getUserIdForMember($member, $document),
				$this->memberService->getUserIdForMember($myMemberInProcess, $document),
			];
			if ($this->isDocumentStoppedForEmployee($myMemberInProcess, $document))
			{
				$rowUserIds[] = $document->stoppedById;
			}

			foreach ($rowUserIds as $rowUserId)
			{
				if ($rowUserId !== null && $rowUserId > 0)
				{
					$userIds[$rowUserId] = $rowUserId;
				}
			}
		}

		return array_values($userIds);
	}

	/**
	 * Reads in a single query which stopped documents of the page already have a successful signer.
	 *
	 * @return array<int, true> set of document ids, keyed for lookup by row
	 */
	private function loadDocumentIdsWithSuccessfulSigners(
		MemberCollection $members,
		DocumentCollection $documents,
	): array
	{
		$documentIds = [];
		foreach ($members as $member)
		{
			$document = $documents->getById((int)$member->documentId);
			if ($document?->status === DocumentStatus::STOPPED)
			{
				$documentIds[$document->id] = $document->id;
			}
		}

		return array_fill_keys(
			$this->memberService->listDocumentIdsWithSuccessfulSigners(array_values($documentIds)),
			true,
		);
	}

	/**
	 * @param list<int> $userIds
	 * @return array<int, array{fullName: string, icon: ?string}>
	 */
	private function loadUserPresentations(array $userIds): array
	{
		if ($userIds === [])
		{
			return [];
		}

		$presentations = [];
		foreach ($this->userService->listByIds($userIds) as $user)
		{
			if ($user->id === null)
			{
				continue;
			}

			$presentations[$user->id] = [
				'fullName' => $this->userService->getUserName($user),
				'icon' => $this->userService->getUserAvatar($user),
			];
		}

		return $presentations;
	}

	/**
	 * @param array<int, array{fullName: string, icon: ?string}> $userPresentations
	 * @return array{fullName: string, icon: ?string}
	 */
	private function getUserPresentation(array $userPresentations, ?int $userId): array
	{
		$presentation = $userId === null ? null : ($userPresentations[$userId] ?? null);

		return $presentation ?? ['fullName' => '', 'icon' => ''];
	}

	/**
	 * @param array<int, array{fullName: string, icon: ?string}> $userPresentations
	 */
	private function buildMemberData(
		Document $document,
		Member $member,
		int $currentUserId,
		array $userPresentations,
		bool $isInitiator = false,
	): ?\Bitrix\Sign\Item\MyDocumentsGrid\Member
	{
		$userIdForMember = $this->memberService->getUserIdForMember($member, $document);
		$shownUserId = $isInitiator ? $document->createdById : $userIdForMember;
		$presentation = $this->getUserPresentation($userPresentations, $shownUserId);
		$isCurrentUser = $userIdForMember === $currentUserId;

		return new \Bitrix\Sign\Item\MyDocumentsGrid\Member(
			isCurrentUser: $isCurrentUser,
			isStopped: $isInitiator ? null : false,
			userId: $shownUserId,
			fullName: $presentation['fullName'],
			icon: $presentation['icon'],
			id: $isInitiator ? null : $member->id,
			role: $isInitiator ? 'initiator' : $member->role,
			status: $isInitiator ? null : $member->status,
		);
	}

	private function getMembersForCurrentUser(
		int $userId,
		int $limit = 10,
		int $offset = 10,
		?MyDocumentsFilter $filter = null,
	): MemberCollection
	{
		return $this->memberRepository->listB2eMembersForMyDocumentsGrid(
			$userId,
			$limit,
			$offset,
			$filter,
		);
	}

	public function getTotalCountMembers(
		int $userId,
		?MyDocumentsFilter $filter = null,
	): int
	{
		return $this->memberRepository->getTotalMemberCollectionCountWithNotWaitStatus($userId, $filter);
	}

	private function getDocuments(MemberCollection $members): DocumentCollection
	{
		$documentIds = [];
		foreach ($members as $member)
		{
			$documentIds[$member->documentId] = $member->documentId;
		}

		if (empty($documentIds))
		{
			return new DocumentCollection();
		}

		return $this->documentRepository->listByIds($documentIds);
	}

	/**
	 * @psalm-return array<int, array<int, Member>>
	 */
	private function getSecondSideMembersForEmployeeMap(
		MemberCollection $membersForCurrentUser,
		DocumentCollection $documents,
		int $userId,
	): array
	{
		$memberIds = [];
		$documentIds = [];

		foreach ($membersForCurrentUser as $member)
		{
			$document = $documents->getById((int)$member->documentId);
			if (!$document)
			{
				continue;
			}

			if ($this->isSecondSideMemberForEmployee($member, $document, $userId))
			{
				$documentIds[] = $document->id;
				$memberIds[] = $member->id;
			}
		}

		if ($documentIds === [])
		{
			return [];
		}

		$secondSideMembers = $this->memberRepository->getSecondSideMembersForMyDocumentsGrid(
			$documentIds,
			$memberIds
		);

		$secondSideMembersMap = [];
		foreach ($secondSideMembers as $member)
		{
			$documentId = $member->documentId;
			$secondSideMembersMap[$documentId][] = $member;
		}

		return $secondSideMembersMap;
	}

	public function isSignedExists(int $userId): bool
	{
		return $this->memberRepository->isAnyMyDocumentsGridInSignedStatus($userId);
	}

	public function isInProgressExists(int $userId): bool
	{
		return $this->memberRepository->isAnyMyDocumentsGridInProgressStatus($userId);
	}

	public function getTotalCountNeedAction(int $userId): int
	{
		$filter = new MyDocumentsFilter(
			statuses: [FilterStatus::NEED_ACTION],
		);

		return $this->getTotalCountMembers($userId, $filter);
	}

	public function getCountNeedActionForSentDocumentsByEmployee(int $userId): int
	{
		$filter = new MyDocumentsFilter(
			role: ActorRole::INITIATOR,
			statuses: [FilterStatus::NEED_ACTION],
		);
		return $this->getTotalCountMembers($userId, $filter);
	}

	/**
	 * Reads the assignee and the signer of the from-employee documents whose row looks into the map, in a
	 * single query, keeping the first member by id per role, as the per-document read did.
	 *
	 * @psalm-param array<int, array<int, Member>> $secondSideMembersMap
	 * @psalm-return array<int, array<string, Member>>
	 */
	private function getFromEmployeeRoleMembersMap(
		MemberCollection $members,
		DocumentCollection $documents,
		array $secondSideMembersMap,
		int $userId,
	): array
	{
		$documentIds = [];
		foreach ($members as $member)
		{
			$document = $documents->getById((int)$member->documentId);
			if (!$document?->isInitiatedByEmployee() || !DocumentStatus::isFinalByDocument($document))
			{
				continue;
			}

			if ($this->readsFromEmployeeRoleMembers($member, $document, $secondSideMembersMap, $userId))
			{
				$documentIds[$document->id] = $document->id;
			}
		}

		if ($documentIds === [])
		{
			return [];
		}

		$roleMembers = $this->memberRepository->listByDocumentIdListAndRoles(
			array_values($documentIds),
			[Role::ASSIGNEE, Role::SIGNER],
			loadEntityNames: false,
		);

		$map = [];
		foreach ($roleMembers as $roleMember)
		{
			if ($roleMember->documentId === null || $roleMember->role === null)
			{
				continue;
			}

			$known = $map[$roleMember->documentId][$roleMember->role] ?? null;
			if ($known === null || (int)$roleMember->id < (int)$known->id)
			{
				$map[$roleMember->documentId][$roleMember->role] = $roleMember;
			}
		}

		return $map;
	}

	/**
	 * The branches of getFromEmployeeFileLink that look into the role members map. A row outside them takes
	 * no file from the map, so its document does not belong to the query.
	 *
	 * @psalm-param array<int, array<int, Member>> $secondSideMembersMap
	 */
	private function readsFromEmployeeRoleMembers(
		Member $myMemberInProcess,
		Document $document,
		array $secondSideMembersMap,
		int $userId,
	): bool
	{
		if ($myMemberInProcess->role === Role::SIGNER)
		{
			return true;
		}

		$displayedMember = $this->resolveDisplayedMember(
			$myMemberInProcess,
			$document,
			$secondSideMembersMap,
			$userId,
		);

		return $displayedMember->status === MemberStatus::STOPPED && $document->status === DocumentStatus::STOPPED;
	}

	/**
	 * @param array<int, ?File> $signedFiles
	 * @psalm-param array<int, array<string, Member>> $fromEmployeeRoleMembersMap
	 */
	private function getFromEmployeeFileLink(
		Document $document,
		Member $initiator,
		Member $myMemberInProcess,
		array $signedFiles,
		array $fromEmployeeRoleMembersMap,
	): ?File
	{
		$documentStatusIsFinal = DocumentStatus::isFinalByDocument($document);

		if ($document->initiatedByType == InitiatedByType::EMPLOYEE && $documentStatusIsFinal)
		{
			$roleMembers = $fromEmployeeRoleMembersMap[$document->id] ?? [];
			if ($initiator->role === Role::SIGNER)
			{
				$resultFile = $this->getFileData($roleMembers[Role::ASSIGNEE] ?? null, $signedFiles);

				if ($resultFile === null)
				{
					return $this->getFileData($roleMembers[Role::SIGNER] ?? null, $signedFiles);
				}

				return $resultFile;
			}

			if ($myMemberInProcess->status === MemberStatus::STOPPED && $document->status === DocumentStatus::STOPPED)
			{
				return $this->getFileData($roleMembers[Role::SIGNER] ?? null, $signedFiles);
			}

			if ($myMemberInProcess->role === Role::ASSIGNEE)
			{
				return null;
			}
		}

		return null;
	}

	private function getEditDate(Member $member): ?DateTime
	{
		if ($member->status === MemberStatus::DONE && $member->role === Role::EDITOR)
		{
			return $member->dateStatusChanged ?? $member->dateSigned;
		}

		return null;
	}

	private function getApprovedDate(Member $member): ?DateTime
	{
		if ($member->status === MemberStatus::DONE && $member->role === Role::REVIEWER)
		{
			return $member->dateStatusChanged ?? $member->dateSigned;
		}

		return null;
	}

	private function getCancelledDate(Member $member, Document $document): ?DateTime
	{
		return match ($member->status)
		{
			MemberStatus::REFUSED, MemberStatus::STOPPED => $member->dateStatusChanged,
			MemberStatus::DONE => $document->isInitiatedByEmployee() ? $this->getDocumentStopDate($document) : null,
			default => $this->getDocumentStopDate($document),
		};
	}

	private function getDocumentStopDate(Document $document): ?DateTime
	{
		return $document->status === DocumentStatus::STOPPED ? $document->dateStatusChanged : null;
	}

	private function getSignDate(Member $member, Document $document): ?DateTime
	{
		return $document->isInitiatedByEmployee() && $member->role === Role::SIGNER && $document->dateSign
			? $document->dateSign
			: $member->dateSigned
			;
	}

	private function isSecondSideMemberForEmployee(
		Member $member,
		Document $document,
		int $userId,
	): bool
	{
		return $this->memberService->getUserIdForMember($member, $document) === $userId
			&& $document->isInitiatedByEmployee()
			&& $member->status !== MemberStatus::STOPPABLE_READY
			&& $member->role === Role::SIGNER;
	}

	private function isDocumentStoppedForEmployee(
		Member $myMemberInProcess,
		Document $document,
	): bool
	{
		return $document->isInitiatedByEmployee()
			&& $document->status === DocumentStatus::STOPPED
			&& $document->stoppedById != null
			&& $myMemberInProcess->role !== Role::REVIEWER;
	}
}