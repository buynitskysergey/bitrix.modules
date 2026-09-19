<?php

namespace Bitrix\Sign\Operation\Signers;

use Bitrix\Main;
use Bitrix\Sign\Contract;
use Bitrix\Sign\Item\SignersList;
use Bitrix\Sign\Repository\SignersList\SignersListRepository;
use Bitrix\Sign\Result\Result;
use Bitrix\Sign\Service\Sign\SignersList\SignerEligibilityService;
use Bitrix\Sign\Service\SignersListService;
use Bitrix\Sign\Type\DateTime;
use Bitrix\Main\Localization\Loc;
use Bitrix\Sign\Service\Container;

final class CopyList implements Contract\Operation
{
	private readonly SignersListService $signersListService;
	private readonly SignersListRepository $signersListRepository;
	private readonly SignerEligibilityService $signerEligibilityService;

	public function __construct(
		private readonly SignersList $list,
		private readonly int $createdByUserId,
		?SignersListRepository $signersListRepository = null,
		?SignersListService $signersListService = null,
		?SignerEligibilityService $signerEligibilityService = null,
	)
	{
		$container = Container::instance();
		$this->signersListRepository = $signersListRepository ?? $container->getSignersListRepository();
		$this->signersListService = $signersListService ?? $container->getSignersListService();
		$this->signerEligibilityService = $signerEligibilityService ?? new SignerEligibilityService();
	}

	public function launch(): Main\Result
	{
		if ($this->list->id === null)
		{
			return Result::createByErrorMessage('List is not saved');
		}

		$newList = new SignersList(
			title: $this->createCopyTitle($this->list->title),
			createdById: $this->createdByUserId,
			id: null,
			dateCreate: new DateTime(),
		);
		$addResult = $this->signersListRepository->add($newList);

		if (!$addResult->isSuccess())
		{
			return $addResult;
		}

		$newListId = $addResult->getId();

		if (!is_int($newListId))
		{
			return $addResult->addError(new Main\Error('Unexpected signers list PK type'));
		}

		$listSigners = $this->signersListService->listSigners($this->list->id);
		$userIds = $this->filterEligibleUserIds($listSigners->getUserIds());

		if ($userIds === [])
		{
			return new Main\Result();
		}

		return $this->signersListService->addUsersToList($newListId, $userIds, $this->createdByUserId);
	}

	/**
	 * Fired and extranet users must not be inherited by the copy: the source list may
	 * have been composed before the eligibility guard existed. Dropping them is silent,
	 * an empty copy is a valid result of copying a list.
	 *
	 * @param int[] $userIds
	 *
	 * @return int[]
	 */
	private function filterEligibleUserIds(array $userIds): array
	{
		$ineligibleUserIds = $this->signerEligibilityService->classify($userIds)->getIneligibleUserIds();

		return array_values(array_diff($userIds, $ineligibleUserIds));
	}

	private function createCopyTitle(string $originalTitle): string
	{
		return Loc::getMessage('SIGN_B2E_SIGNERS_LIST_COPY_TITLE',[
			'#TITLE#' => $originalTitle,
		]);
	}
}
