<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Document;

use Bitrix\Note\Internal\Exceptions\AccessDeniedException;
use Bitrix\Note\Internal\Exceptions\DocumentNotFoundException;
use Bitrix\Note\Public\Provider\BacklinkProvider;

/**
 * Bundles the backlinks counter into the document bootstrap payload, the way
 * DocumentViewsSnapshotResolver does it for views: the widget adopts the number instead of asking
 * for it on mount.
 *
 * The slice degrades to the neutral value instead of throwing — a document opened from the recycle
 * bin or a target the caller may not read must not cost the whole payload.
 */
final class DocumentBacklinksSnapshotResolver
{
	public function __construct(
		private readonly BacklinkProvider $backlinkProvider = new BacklinkProvider(),
	) {}

	/**
	 * @return array{count: int, isCapped: bool}
	 */
	public function resolve(int $documentId): array
	{
		try
		{
			return $this->backlinkProvider->getCount($documentId);
		}
		catch (AccessDeniedException|DocumentNotFoundException)
		{
			return $this->neutral();
		}
	}

	/**
	 * @return array{count: int, isCapped: bool}
	 */
	private function neutral(): array
	{
		return ['count' => 0, 'isCapped' => false];
	}
}
