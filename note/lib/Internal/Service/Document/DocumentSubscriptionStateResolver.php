<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Document;

use Bitrix\Note\Internal\Exceptions\AccessDeniedException;
use Bitrix\Note\Internal\Exceptions\DocumentNotFoundException;
use Bitrix\Note\Public\Provider\SubscriptionProvider;

/**
 * Bundles the current user's subscription state for the bell control into the document bootstrap
 * payload — same rationale as DocumentViewsSnapshotResolver / DocumentLastChangeResolver: the bell
 * adopts this instead of issuing its own SubscriptionController::getState request on mount. Reuses
 * SubscriptionProvider::getState (only its `document` half — the bell never uses the collection
 * block). Degrades to a neutral "not subscribed" default so the bootstrap never fails over the bell.
 */
final class DocumentSubscriptionStateResolver
{
	public function __construct(
		private readonly SubscriptionProvider $subscriptionProvider = new SubscriptionProvider(),
	) {}

	/**
	 * @return array{subscribed: bool, mode: ?string, muted: bool, inherited: bool, inheritedSource: ?string, inheritedTitle: ?string}
	 */
	public function resolve(int $documentId, int $userId): array
	{
		$default = [
			'subscribed' => false,
			'mode' => null,
			'muted' => false,
			'inherited' => false,
			'inheritedSource' => null,
			'inheritedTitle' => null,
		];

		if ($documentId <= 0 || $userId <= 0)
		{
			return $default;
		}

		try
		{
			return $this->subscriptionProvider->getState($userId, $documentId)['document'];
		}
		catch (DocumentNotFoundException|AccessDeniedException)
		{
			return $default;
		}
	}
}
