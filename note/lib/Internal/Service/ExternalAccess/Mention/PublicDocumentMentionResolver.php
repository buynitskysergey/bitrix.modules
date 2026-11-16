<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\ExternalAccess\Mention;

use Bitrix\Note\Internal\Mention\MentionType;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Service\Mention\MentionEntityResolver;
use Bitrix\Note\Internal\Service\Mention\ResolvedMention;

/**
 * Mentions of documents as a visitor of a public link may know them.
 *
 * The scope of the link is wired in at construction, exactly like in ScopedDocumentReader: there is
 * no method to ask about a document without it. A mention outside the scope answers the single
 * public reason and carries no title - a title alone tells that someone else's document exists, and
 * the mention token is written by an employee, so any id at all can appear in the text.
 *
 * No address is given either. The route of a mention in the ordinary circuit leads inside the
 * portal (`/note/document/{id}/`), where a visitor has nothing to open; the client of the public
 * page routes by the id of the available mention itself.
 */
final class PublicDocumentMentionResolver implements MentionEntityResolver
{
	public function __construct(
		private readonly int $collectionId,
		private readonly DocumentRepository $repository = new DocumentRepository(),
	) {}

	public function resolve(array $ids): array
	{
		$titles = $this->repository->getLiveTitlesInCollection($this->collectionId, $ids);

		$resolved = [];
		foreach ($ids as $rawId)
		{
			$id = (int)$rawId;
			if ($id <= 0)
			{
				continue;
			}

			$resolved[$id] = isset($titles[$id])
				? ResolvedMention::available(
					type: MentionType::Document->value,
					id: $id,
					label: $titles[$id],
					url: null,
				)
				: PublicMentionReason::unavailable(MentionType::Document->value, $id)
			;
		}

		return $resolved;
	}
}
