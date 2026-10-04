<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Controller;

use Bitrix\Main\Context;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Note\Internal\Access\Service\CollectionAccessService;
use Bitrix\Note\Internal\Access\Service\DocumentAccessService;
use Bitrix\Note\Public\Provider\CollectionProvider;
use Bitrix\Note\Public\Provider\DocumentProvider;

/**
 * LEVEL_VIEW check on the target of a personal per-user record (a favorite, a subscription): only
 * something visible may be starred or subscribed to. The two answers are kept apart - a missing
 * target is 404, an invisible one is 403 - and neither of them carries data about the object.
 *
 * Each host controller keeps its own vocabulary (FavoriteTable::ENTITY_TYPE_*, SubscriptionTable::
 * SCOPE_*) and dispatches to the two methods below.
 *
 * Expects a Bitrix\Main\Engine\Controller host (addError()).
 */
trait TargetViewAccessTrait
{
	private function assertTargetDocumentViewAccess(int $documentId): bool
	{
		$ownership = (new DocumentProvider())->getOwnershipInfo($documentId);
		if ($ownership === null)
		{
			Context::getCurrent()->getResponse()->setStatus(404);

			return false;
		}

		if (!DocumentAccessService::currentUserHasLevel($documentId, (int)$ownership['collectionId'], DocumentAccessService::LEVEL_VIEW))
		{
			Context::getCurrent()->getResponse()->setStatus(403);
			$this->denyAccess();

			return false;
		}

		return true;
	}

	private function assertTargetCollectionViewAccess(int $collectionId): bool
	{
		if ((new CollectionProvider())->getById($collectionId) === null)
		{
			Context::getCurrent()->getResponse()->setStatus(404);

			return false;
		}

		if (!CollectionAccessService::currentUserHasLevel($collectionId, CollectionAccessService::LEVEL_VIEW))
		{
			Context::getCurrent()->getResponse()->setStatus(403);
			$this->denyAccess();

			return false;
		}

		return true;
	}

	private function denyAccess(): void
	{
		$this->addError(new Error((string)(Loc::getMessage('NOTE_ACCESS_DENIED'))));
	}
}
