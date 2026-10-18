<?php

namespace Bitrix\Im\V2\Integration\HumanResources;

use Bitrix\HumanResources\Config\Feature;
use Bitrix\HumanResources\Config\Storage;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Type\AccessCodeType;
use Bitrix\HumanResources\Type\RelationEntitySubtype;
use Bitrix\HumanResources\Type\RelationEntityType;
use Bitrix\Im\V2\Chat;
use Bitrix\Im\V2\Result;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Loader;
use Bitrix\Main\UI\EntitySelector\Converter;

class Structure
{
	/** Hard limit of NodeRelationService::getUsersNotInRelation(), not a tuning knob */
	private const USERS_PER_STRUCTURE_QUESTION = 500;

	protected Chat $chat;

	public function __construct(Chat $chat)
	{
		$this->chat = $chat;
	}

	public static function isTeamsAvailable(): bool
	{
		return Loader::includeModule('humanresources')
			&& Storage::instance()->isCompanyStructureConverted()
			&& Feature::instance()->isCrossFunctionalTeamsAvailable()
		;
	}

	public static function splitEntities(array $entities): array
	{
		$entities = static::convertEntities($entities);
		$users = [];
		$structureNodes = [];

		foreach ($entities as $entity)
		{
			if (str_starts_with($entity, 'U'))
			{
				$users[] = (int)mb_substr($entity, 1);
			}
			if (static::isStructureNode($entity))
			{
				$structureNodes[] = $entity;
			}
		}

		return [$users, $structureNodes];
	}

	public static function isSyncAvailable(): bool
	{
		return Loader::includeModule('humanresources')
			&& Storage::instance()->isCompanyStructureConverted()
		;
	}

	/**
	 * Whether the company structure still holds the user in this chat through a linked node.
	 * Returns null when the structure could not be asked at all, so the caller can keep a restriction
	 * instead of widening rights on an unknown answer.
	 */
	public function holdsUser(int $userId): ?bool
	{
		return $this->holdsUsers([$userId])[$userId] ?? null;
	}

	/**
	 * Batch form of holdsUser() for callers that delete members in a loop: without it every member
	 * carrying a structure mark costs its own multi-join query on an interactive request.
	 *
	 * @param array<int|string> $userIds
	 * @return array<int, bool|null> the answer of holdsUser() per user id
	 */
	public function holdsUsers(array $userIds): array
	{
		$userIds = array_values(array_unique(array_map('intval', $userIds)));

		if (empty($userIds))
		{
			return [];
		}

		if (!$this->isStructureAvailable())
		{
			return array_fill_keys($userIds, false);
		}

		$answers = [];

		// The chunk size is the contract of the service (TooMuchDataException), not a tuning knob.
		foreach (array_chunk($userIds, self::USERS_PER_STRUCTURE_QUESTION) as $chunk)
		{
			$chunkAnswers = $this->askStructureAboutUsers($chunk);
			$answers += $chunkAnswers;

			if (in_array(null, $chunkAnswers, true))
			{
				// The chunks left are the same failing call repeated - each heavy, each another warning
				// in the log - and unknown is what they would answer anyway. Unknown keeps the refusal.
				return $answers + array_fill_keys($userIds, null);
			}
		}

		return $answers;
	}

	/**
	 * @param list<int> $userIds
	 * @return array<int, bool|null>
	 */
	private function askStructureAboutUsers(array $userIds): array
	{
		try
		{
			$usersNotInNodes = Container::getNodeRelationService()->getUsersNotInRelation(
				RelationEntityType::CHAT,
				(int)$this->chat->getId(),
				$userIds,
			);
		}
		catch (\Throwable $exception)
		{
			$this->reportStructureFailure($userIds, $exception);

			return array_fill_keys($userIds, null);
		}

		// Reverse polarity: the service answers with those who are NOT in the linked nodes.
		$notHeld = array_fill_keys(array_map('intval', $usersNotInNodes), true);
		$answers = [];

		foreach ($userIds as $userId)
		{
			$answers[$userId] = !isset($notHeld[$userId]);
		}

		return $answers;
	}

	/**
	 * Without a trace an operator is left with a refusal and no reason for it - but the trace is
	 * diagnostics, and a broken logger must not turn a fail-closed refusal into an unhandled exception
	 * on the deletion path.
	 *
	 * @param list<int> $userIds
	 */
	private function reportStructureFailure(array $userIds, \Throwable $exception): void
	{
		try
		{
			$this->writeStructureFailureWarning($userIds, $exception);
		}
		catch (\Throwable)
		{
			// There is nowhere left to report a broken logger to.
		}
	}

	/**
	 * @param list<int> $userIds
	 */
	protected function writeStructureFailureWarning(array $userIds, \Throwable $exception): void
	{
		(new LoggerFactory())->createById('im.HumanResources.HoldsUser')?->warning(
			'Failed to ask the company structure whether it holds the users',
			[
				'chatId' => (int)$this->chat->getId(),
				'userIds' => $userIds,
				'exception' => $exception->getMessage(),
			],
		);
	}

	/**
	 * Seam over the static predicate: a subclass can substitute it, the static call cannot be stubbed.
	 */
	protected function isStructureAvailable(): bool
	{
		return static::isSyncAvailable();
	}

	public function link(array $structureNodeIds): Result
	{
		$result = new Result();

		if (empty($structureNodeIds))
		{
			return $result;
		}

		if (!Loader::includeModule('humanresources'))
		{
			return $result->addError(new Error(Error::LINK_ERROR));
		}

		$nodeRelationService = Container::getNodeRelationService();

		foreach ($structureNodeIds as $structureNodeId)
		{
			try {
				$nodeRelationService->linkEntityToNodeByAccessCode(
					$structureNodeId,
					RelationEntityType::CHAT,
					$this->chat->getId(),
					RelationEntitySubtype::fromChatType($this->chat->getType()),
				);
			}
			catch (\Exception $exception)
			{
				$result->addError(new Error(Error::LINK_ERROR));
			}
		}

		return $result;
	}

	public function unlink(array $structureNodeIds): Result
	{
		$result = new Result();

		if (empty($structureNodeIds))
		{
			return $result;
		}

		if (!Loader::includeModule('humanresources'))
		{
			return $result->addError(new Error(Error::UNLINK_ERROR));
		}

		$nodeRelationService = Container::getNodeRelationService();

		foreach ($structureNodeIds as $structureNodeId)
		{
			try {
				$nodeRelationService->unlinkEntityFromNodeByAccessCode(
					$structureNodeId,
					RelationEntityType::CHAT,
					$this->chat->getId(),
				);
			}
			catch (\Exception $exception)
			{
				$result->addError(new Error(Error::UNLINK_ERROR));
			}
		}

		return $result;
	}

	public function unlinkAll(): Result
	{
		$result = new Result();

		if (!Loader::includeModule('humanresources'))
		{
			return $result->addError(new Error(Error::UNLINK_ERROR));
		}

		$nodeRelationRepository = Container::getNodeRelationRepository();

		try
		{
			$nodeRelationRepository->deleteRelationByEntityTypeAndEntityIds(
				RelationEntityType::CHAT,
				[(int)$this->chat->getId()],
			);
		}
		catch (\Exception)
		{
			return $result->addError(new Error(Error::UNLINK_ERROR));
		}

		return $result;
	}

	protected static function convertEntities(array $entities): array
	{
		if (!Loader::includeModule('ui'))
		{
			return [];
		}

		return Converter::convertToFinderCodes($entities);
	}

	/**
	 * @return list<string>
	 */
	public function getNodesAccessCodes(): array
	{
		$accessCodes = [];

		if (!Loader::includeModule('humanresources'))
		{
			return $accessCodes;
		}

		$nodeRelationService = Container::getNodeRelationService();

		$links = $nodeRelationService->findAllRelationsByEntityTypeAndEntityId(
			RelationEntityType::CHAT,
			$this->chat->getId(),
		);

		foreach ($links as $link)
		{
			if ($link->node === null)
			{
				continue;
			}

			if ($link->node->isDepartment())
			{
				$accessCodes[] = $link->withChildNodes
					? str_replace('D', 'DR', $link->node->accessCode)
					: $link->node->accessCode;
			}
			if ($link->node->isTeam())
			{
				$accessCodes[] = $link->withChildNodes
					? AccessCodeType::HrTeamRecursiveType->buildAccessCode($link->nodeId)
					: AccessCodeType::HrTeamType->buildAccessCode($link->nodeId);
			}
		}

		return $accessCodes;
	}

	private static function isStructureNode(string $entity): bool
	{
		if (!Loader::includeModule('humanresources'))
		{
			return false;
		}

		$prefixes = [
			...AccessCodeType::getIntranetDepartmentTypesPrefixes(),
			...AccessCodeType::getTeamTypesPrefixes(),
		];
		foreach ($prefixes as $prefix)
		{
			if (str_starts_with($entity, $prefix))
			{
				return true;
			}
		}

		return false;
	}
}
