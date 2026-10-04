<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\History;

use Bitrix\Main\Type\DateTime;
use Bitrix\Note\Internal\Repository\DocumentViewRepository;

/**
 * [P3.T2] Records "who viewed the document, when" on awareness `join` — the only
 * awareness message that is guaranteed to reach the server (see
 * CollaborationSyncController::sendAwarenessAction). Best-effort by design: a
 * failure here must never break awareness delivery or document opening, so
 * track() swallows its own errors instead of pushing that responsibility onto
 * every call site.
 *
 * Deliberately NOT gated by Configuration::isActivityEnabled() (SDD F2): the hook
 * is cheap and keeps the view counter accurate even while the activity/history
 * UI is switched off — only the widget itself is hidden when the flag is false.
 */
final class ViewTrackingService
{
	public function __construct(
		private readonly DocumentViewRepository $documentViewRepository = new DocumentViewRepository(),
	) {}

	public function track(int $documentId, int $userId): void
	{
		if ($documentId <= 0 || $userId <= 0)
		{
			return;
		}

		try
		{
			$this->documentViewRepository->track($documentId, $userId, new DateTime());
		}
		catch (\Throwable)
		{
			// Best-effort: view tracking must never break awareness delivery.
		}
	}
}
