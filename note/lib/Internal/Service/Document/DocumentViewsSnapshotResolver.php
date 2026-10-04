<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Document;

use Bitrix\Note\Internal\Exceptions\AccessDeniedException;
use Bitrix\Note\Internal\Exceptions\DocumentNotFoundException;
use Bitrix\Note\Public\Provider\ViewProvider;

/**
 * [#6] Bundles the initial "who viewed" snapshot into the document bootstrap payload
 * (DocumentController::getAction / getOpenContextAction) so the client doesn't need a
 * separate ViewsWidgetComponent.mounted() request. Reuses ViewProvider — no duplicated
 * query logic; only the pagination cursor is dropped, it isn't needed for a bootstrap
 * snapshot (the client still calls DocumentController::getViews for that).
 */
final class DocumentViewsSnapshotResolver
{
	private const DEFAULT_LIMIT = 50;

	public function __construct(
		private readonly ViewProvider $viewProvider = new ViewProvider(),
	) {}

	/**
	 * @return array{
	 *   uniqueCount: int,
	 *   viewers: array<int, array{userId: int, name: string, avatar: ?string, viewedAt: string}>
	 * }
	 */
	public function resolve(int $documentId, int $limit = self::DEFAULT_LIMIT): array
	{
		try
		{
			$snapshot = $this->viewProvider->getViews($documentId, $limit);
		}
		catch (DocumentNotFoundException|AccessDeniedException)
		{
			// Bootstrap must not fail the whole document load over the views widget — same
			// silent degrade the widget itself already applied to a failed getViews call.
			return ['uniqueCount' => 0, 'viewers' => []];
		}

		return [
			'uniqueCount' => $snapshot['uniqueCount'],
			'viewers' => $snapshot['viewers'],
		];
	}
}
