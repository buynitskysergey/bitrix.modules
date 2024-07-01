<?php
namespace Bitrix\IntranetMobile\Controller;

use Bitrix\Intranet\Controller\Invite;
use Bitrix\IntranetMobile\Dto\SortingDto;
use Bitrix\IntranetMobile\Dto\FilterDto;
use Bitrix\Main\Engine\ActionFilter\CloseSession;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\IntranetMobile\Provider\UserProvider;

class Employees extends Base
{
	public function configureActions(): array
	{
		return [
			'getUserListAction' => [
				'+prefilters' => [
					new CloseSession(),
				],
			],
		];
	}

	public function getUserListAction(
		?array $filterParams = null,
		?array $sortingParams = null,
		?PageNavigation $nav = null,
	): array
	{
		$filter = $filterParams ? new FilterDto(...$filterParams) : new FilterDto();
		$sorting = $sortingParams ? new SortingDto(...$sortingParams) : new SortingDto();
		$userProvider = new UserProvider();

		$result = $userProvider->getByPage(filter: $filter, sorting: $sorting, nav: $nav);

		$users = $result['users'];
		$isOnlyCurrentUser = count($users) === 1 && $users[0]->id === (int)$this->getCurrentUser()->getId();

		if ($isOnlyCurrentUser && $userProvider->isDefaultOrEmptyFilter($filter))
		{
			return [];
		}

		return $result;
	}

	public function getUsersByIdsAction(array $ids): array
	{
		return (new UserProvider())->getUsersByIds($ids);
	}

	public function reinviteAction(int $userId, bool $isExtranetUser)
	{
		$isExtranetUser = $isExtranetUser ? 'Y' : 'N';

		return $this->forward(Invite::class, 'reinvite', [
			'params' => [
				'userId' => $userId,
				'extranet' => $isExtranetUser,
			],
		]);
	}

	public function getSearchBarPresetsAction(): array
	{
		return [
			'presets' => (new UserProvider())->getPresets(),
			'counters' => [],
		];
	}
}