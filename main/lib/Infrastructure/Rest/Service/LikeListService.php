<?php

namespace Bitrix\Main\Infrastructure\Rest\Service;

use Bitrix\Main\Event;
use Bitrix\Main\ModuleManager;
use Bitrix\Rest\RestException;

class LikeListService
{
	public const LIST_LIMIT = 20;

	/**
	 * @return array{
	 *   items: list<array<string, mixed>>,
	 *   total: int
	 * }
	 */
	public function getList(
		string $entityTypeId,
		int $entityId,
		int $page,
		int $limit,
		string $pathToUserProfile = '',
		?string $reaction = null,
		?int $viewerUserId = null,
	): array
	{
		$queryParams = [
			'ENTITY_TYPE_ID' => $entityTypeId,
			'ENTITY_ID' => $entityId,
			'LIST_PAGE' => $page,
			'LIST_LIMIT' => $limit,
			'LIST_TYPE' => 'plus',
			'USE_REACTIONS_CACHE' => 'Y',
		];

		$extranetInstalled = false;
		$mailInstalled = false;
		if (ModuleManager::isModuleInstalled('extranet'))
		{
			$extranetInstalled = true;
			$queryParams['USER_SELECT'] = ['UF_DEPARTMENT'];
		}
		if (ModuleManager::isModuleInstalled('mail'))
		{
			$mailInstalled = true;
			$queryParams['USER_FIELDS'] = [
				'ID',
				'NAME',
				'LAST_NAME',
				'SECOND_NAME',
				'LOGIN',
				'PERSONAL_PHOTO',
				'EXTERNAL_AUTH_ID',
			];
		}

		if ($reaction !== null && $reaction !== '')
		{
			$queryParams['REACTION'] = $reaction;
		}

		$res = \CRatings::getRatingVoteList($queryParams);

		$items = [];
		foreach ($res['items'] as $value)
		{
			$userVote = [
				'USER_ID' => $value['ID'],
				'VOTE_VALUE' => $value['VOTE_VALUE'],
				'PHOTO' => $value['PHOTO'],
				'PHOTO_SRC' => $value['PHOTO_SRC'],
				'FULL_NAME' => $value['FULL_NAME'],
				'URL' => \CUtil::jSEscape(\CComponentEngine::makePathFromTemplate($pathToUserProfile, [
					'UID' => $value['USER_ID'],
					'user_id' => $value['USER_ID'],
					'USER_ID' => $value['USER_ID'],
				])),
			];

			if (
				$mailInstalled
				&& ($value['EXTERNAL_AUTH_ID'] ?? null) === 'email'
			)
			{
				$userVote['USER_TYPE'] = 'mail';
			}
			elseif (
				$extranetInstalled
				&& (
					empty($value['UF_DEPARTMENT'])
					|| (int)($value['UF_DEPARTMENT'][0] ?? 0) <= 0
				)
			)
			{
				$userVote['USER_TYPE'] = 'extranet';
			}

			$items[] = $userVote;
		}

		if ($viewerUserId !== null && $page === 1)
		{
			$event = new Event(
				'main',
				'onRatingListViewed',
				[
					'entityTypeId' => $entityTypeId,
					'entityId' => $entityId,
					'userId' => $viewerUserId,
				],
			);
			$event->send();
		}

		if ($reaction !== null && $reaction !== '' && $reaction !== 'all')
		{
			$total = isset($res['reactions'][$reaction])
				? (int)$res['reactions'][$reaction]
				: 0;
		}
		else
		{
			$total = (int)$res['items_all'];
		}

		return [
			'items' => $items,
			'total' => $total,
		];
	}

	/**
	 * @return array<string, int>
	 */
	public function getReactions(string $entityTypeId, int $entityId): array
	{
		if ($entityTypeId === '' || $entityId <= 0)
		{
			throw new RestException(
				'Wrong entity data.',
				RestException::ERROR_ARGUMENT,
				\CRestServer::STATUS_WRONG_REQUEST,
			);
		}

		$reactionResult = \CRatings::getRatingVoteReaction([
			'ENTITY_TYPE_ID' => $entityTypeId,
			'ENTITY_ID' => $entityId,
			'USE_REACTIONS_CACHE' => 'Y',
		]);

		return $reactionResult['reactions'];
	}
}
