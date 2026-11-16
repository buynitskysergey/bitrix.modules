<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\ExternalAccess;

use Bitrix\Note\Internal\Mention\MentionType;
use Bitrix\Note\Internal\Repository\DocumentRepository;
use Bitrix\Note\Internal\Service\ExternalAccess\Mention\PublicMentionReason;
use Bitrix\Note\Internal\Service\Link\DocumentLinkExtractor;
use Bitrix\Note\Internal\Service\Mention\MentionResolver;

/**
 * The mentions of one document, as a visitor of a public link may know them.
 *
 * Two ways in, and both answer out of the same resolvers. A client that names its mentions is
 * answered about exactly those; a client that names none is answered about the mentions written in
 * the stored text of the document.
 *
 * A named list is a probe by construction, and it is allowed to be one because the answer holds
 * nothing to probe with (the reasoning of `listRequested`, and see PublicMentionResolverFactory):
 * an employee, a knowledge base, a task and a folder of Disk are refused with no lookup at all, and
 * a document is resolved in the very scope the public tree of the link already publishes - live,
 * unarchived, not in the recycle bin and not the hidden carrier of the description. So the only
 * thing learnt from the answer is what the visitor has been shown anyway.
 *
 * The list used to be intersected with the stored text of the document, and that is what this note
 * used to defend. It was dropped because the stored text is a projection: it is rebuilt after the
 * editor settles, and until then a mention written a moment ago is in the text the visitor is
 * reading and not in the text the server is checking against. The intersection turned that lag into
 * a lie - the chip of a document the visitor may open was sealed as "unavailable" for the rest of
 * the page's life.
 *
 * The scope is wired in at construction and there is no method without it, as in
 * ScopedDocumentReader: a knowledge base accepted as an argument could be passed wrongly by the
 * next caller, and the mistake would be silent - titles of foreign documents look like a valid
 * answer.
 */
final class PublicMentionService
{
	/**
	 * The ceiling of the ordinary batch, kept for the same reason: resolving a mention is a lookup,
	 * and a document with a thousand of them may not turn one request into a thousand.
	 */
	private const MAX_MENTIONS = 200;

	public function __construct(
		private readonly PublicScope $scope,
		private readonly DocumentRepository $repository = new DocumentRepository(),
		private readonly DocumentLinkExtractor $extractor = new DocumentLinkExtractor(),
		private readonly PublicMentionResolverFactory $resolverFactory = new PublicMentionResolverFactory(),
	) {}

	/**
	 * `isCapped` is named after the counter of backlinks and means the same thing: the answer was cut
	 * at the ceiling. Silent truncation was the worse of the two - a client cannot tell "there are
	 * more than two hundred mentions" from "the rest are unavailable", and unlike the production
	 * batch it does not know how many it asked about, because it asked about a document.
	 *
	 * A client that already knows which mentions it is about to draw may name them, and then only
	 * those are resolved - a page drawing three chips of a document that mentions five hundred people
	 * pays three lookups instead of two hundred, and the mentions it draws are the ones in the text it
	 * is drawing rather than the ones in the projection the server keeps.
	 *
	 * @param array<array{type: string, id: int}> $requested the mentions the client is going to draw;
	 *        an empty list - and an absent one - asks about every mention of the document, as before
	 * @return array{mentions: array<int, array{type: string, id: int, label: ?string, url: ?string, available: bool, reason: ?string}>, isCapped: bool}
	 */
	public function listByDocument(int $documentId, array $requested = []): array
	{
		if ($requested !== [])
		{
			// The document is not read at all on this path: the named list is answered by the scope of
			// the link, and reading the projection to check the list against it is exactly what made a
			// freshly written mention look unavailable.
			//
			// The ceiling is applied to the request, so a cut is reported even when everything that
			// survived normalization was answered.
			return $this->buildAnswer(
				$this->normalizeRequested(array_slice($requested, 0, self::MAX_MENTIONS)),
				count($requested) > self::MAX_MENTIONS,
			);
		}

		$markdown = (string)$this->repository->getRawMarkdown($documentId);
		$found = $this->extractor->extractMentions($markdown);

		return $this->buildAnswer(
			array_slice($found, 0, self::MAX_MENTIONS),
			count($found) > self::MAX_MENTIONS,
		);
	}

	/**
	 * The same answer for a list asked by the client, for the one surface that cannot name its
	 * document: the description of the knowledge base is carried by a document a visitor may not
	 * know the id of, so there is nothing to ask `listByDocument` about (see
	 * DescriptionController::listMentionsAction).
	 *
	 * The list is a probe by construction, and it is allowed to be one because the answer holds
	 * nothing to probe with: a document is resolved inside the scope of the link alone, and an
	 * employee, a knowledge base, a task or a folder of Disk is refused without a lookup at all. So
	 * the only thing learnt from the answer is what the public tree of the link already shows.
	 *
	 * @param array<array{type: string, id: int}> $items
	 * @return array{mentions: array<int, array{type: string, id: int, label: ?string, url: ?string, available: bool, reason: ?string}>, isCapped: bool}
	 */
	public function listRequested(array $items): array
	{
		$requested = array_slice(array_values($items), 0, self::MAX_MENTIONS);
		$isCapped = count($items) > self::MAX_MENTIONS;
		if ($requested === [])
		{
			return ['mentions' => [], 'isCapped' => false];
		}

		// Not normalizeRequested(): this surface answers one item per item asked, an unusable pair
		// included. A dropped element is a difference of shape, and shape is something a caller reads.
		$normalized = [];
		foreach ($requested as $item)
		{
			$normalized[] = [
				'type' => is_array($item) ? (string)($item['type'] ?? '') : '',
				'id' => is_array($item) ? (int)($item['id'] ?? 0) : 0,
			];
		}

		return ['mentions' => $this->resolveToPublicItems($normalized), 'isCapped' => $isCapped];
	}

	/**
	 * @param array<array{type: string, id: int}> $mentions
	 * @return array{mentions: array<int, array{type: string, id: int, label: ?string, url: ?string, available: bool, reason: ?string}>, isCapped: bool}
	 */
	private function buildAnswer(array $mentions, bool $isCapped): array
	{
		return [
			'mentions' => $mentions === [] ? [] : $this->resolveToPublicItems($mentions),
			'isCapped' => $isCapped,
		];
	}

	/**
	 * What the client named, in the order it named it, with everything unusable dropped: a pair
	 * without a type or without a positive id names no mention at all, and the same pair twice is one
	 * mention - the answer carries a pair once, so a request that repeats it would only pay twice.
	 *
	 * @param array<mixed> $requested
	 * @return array<int, array{type: string, id: int}>
	 */
	private function normalizeRequested(array $requested): array
	{
		$normalized = [];
		foreach ($requested as $item)
		{
			if (!is_array($item))
			{
				continue;
			}

			$type = (string)($item['type'] ?? '');
			$id = (int)($item['id'] ?? 0);
			if ($type !== '' && $id > 0)
			{
				$normalized[$type . ':' . $id] = ['type' => $type, 'id' => $id];
			}
		}

		return array_values($normalized);
	}

	/**
	 * @param array<array{type: string, id: int}> $mentions
	 * @return array<int, array{type: string, id: int, label: ?string, url: ?string, available: bool, reason: ?string}>
	 */
	private function resolveToPublicItems(array $mentions): array
	{
		$resolver = new MentionResolver(
			$this->resolverFactory->createResolvers($this->scope->getCollectionId()),
		);

		$items = [];
		foreach ($resolver->resolve($mentions) as $resolved)
		{
			// Composed key by key, not filtered out of ResolvedMention::toArray(): `avatar` and
			// `isCurrentUser` of the ordinary answer are about an employee the visitor has no right
			// to, and a key that is never built cannot leak.
			$items[] = [
				// A value of the enumeration or nothing: a token of a type nobody knows would
				// otherwise send the asked-about string straight back into the answer.
				'type' => MentionType::tryFrom($resolved->type)?->value ?? '',
				'id' => $resolved->id,
				'label' => $resolved->label,
				'url' => $resolved->url,
				'available' => $resolved->available,
				// One reason for everything unavailable, including a token of a type nobody knows:
				// the ordinary answer distinguishes `invalid` there, and the difference is of no use
				// to a visitor while being one more thing to read the text of a document by.
				'reason' => $resolved->reason === null ? null : PublicMentionReason::UNAVAILABLE,
			];
		}

		return $items;
	}
}
