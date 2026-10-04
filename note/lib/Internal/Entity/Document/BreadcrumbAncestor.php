<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Entity\Document;

/**
 * One rung of a document's breadcrumb chain. The chain is produced in three places (the plain
 * breadcrumb, the privacy-truncated one for a shared document, and the direct-open bootstrap) and
 * consumed by the same frontend contract, so the shape lives here instead of being re-spelled as a
 * bare array at every producer — a renamed key would otherwise break the reader silently.
 *
 * Owner of the wire contract: the frontend reads only what toArray() emits.
 */
final readonly class BreadcrumbAncestor
{
	public function __construct(
		public int $id,
		public string $title,
	) {}

	/**
	 * @return array{id: int, title: string}
	 */
	public function toArray(): array
	{
		return [
			'id' => $this->id,
			'title' => $this->title,
		];
	}

	/**
	 * @param self[] $ancestors
	 * @return array<int, array{id: int, title: string}>
	 */
	public static function listToArray(array $ancestors): array
	{
		return array_map(static fn(self $ancestor): array => $ancestor->toArray(), $ancestors);
	}
}
