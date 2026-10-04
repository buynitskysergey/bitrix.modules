<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service;

use Bitrix\Main\Application;
use Bitrix\Main\Event;
use Bitrix\Note\Public\Event\OnDocumentContentSettledEvent;
use Bitrix\Note\Public\Event\OnDocumentLifecycleEvent;

/**
 * Publishes note domain backend-events (EVENT-NOTE-01 / EVENT-NOTE-02).
 *
 * This is note-internal machinery: only note's own commands emit its domain events. The published
 * contract lives in the {@see OnDocumentLifecycleEvent} / {@see OnDocumentContentSettledEvent} value
 * objects (Public); this class only assembles them and defers the send.
 *
 * Emission is deferred to after-REQUEST, not bound to a specific transaction commit: like
 * {@see \Bitrix\Note\Internal\Service\Collaboration\PushNotificationService::dispatchAfterCommit},
 * the actual `Event::send()` runs from a background job once the surrounding request has finished.
 * For the common case — the command's own mutation commits within the request — this is effectively
 * after-commit. It is NOT a real commit hook, so if a caller wraps the command in an OUTER transaction
 * that later rolls back, the event still fires: subscribers must tolerate a spurious/stale event.
 * (The RAG sync subscriber is idempotent — content is fingerprinted and reconciled — so a stale event
 * self-corrects on the next drain/reconcile rather than corrupting state.) A true commit-bound emission
 * would need a framework transaction-commit subscription that Bitrix Main does not expose.
 */
class DomainEventPublisher
{
	/**
	 * EVENT-NOTE-01 — onDocumentLifecycle.
	 *
	 * @param string $reason One of OnDocumentLifecycleEvent reason constants.
	 * @param int|null $collectionId For `moved` — the NEW collection; null only for `hardDeleted`.
	 * @param int[] $documentIds Full list of affected document ids.
	 * @param array{collectionId: int, parentId: int|null}|null $from Only for `moved` — old root location.
	 * @param array{collectionId: int, parentId: int|null}|null $to Only for `moved` — new root location.
	 */
	public function emitLifecycle(
		string $reason,
		?int $collectionId,
		array $documentIds,
		?array $from = null,
		?array $to = null,
	): void
	{
		$event = new OnDocumentLifecycleEvent($reason, $collectionId, $documentIds, $from, $to);

		// Empty cascade => nothing to say. Normalization already happened in the event ctor,
		// so ask the event rather than re-normalizing here.
		if ($event->getDocumentIds() === [])
		{
			return;
		}

		$this->dispatchAfterRequest($event);
	}

	/**
	 * EVENT-NOTE-02 — onDocumentContentSettled.
	 *
	 * @param string $reason One of OnDocumentContentSettledEvent reason constants.
	 */
	public function emitContentSettled(string $reason, int $collectionId, int $documentId): void
	{
		$this->dispatchAfterRequest(new OnDocumentContentSettledEvent($reason, $collectionId, $documentId));
	}

	/**
	 * Defers `Event::send()` until after the response is sent — the same mechanism
	 * PushNotificationService uses for lifecycle pushes.
	 */
	private function dispatchAfterRequest(Event $event): void
	{
		Application::getInstance()->addBackgroundJob(static function () use ($event): void {
			$event->send();
		});
	}
}
