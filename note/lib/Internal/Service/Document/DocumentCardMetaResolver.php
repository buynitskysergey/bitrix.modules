<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Document;

use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Note\Internal\Model\DocumentTable;
use Bitrix\Note\Internal\Service\Search\MarkdownStripper;
use Bitrix\Note\Internal\Service\Search\SnippetExtractor;
use Bitrix\Note\Internal\Service\User\AuthorResolver;
use Bitrix\Note\Internal\Service\User\SystemUser;

/**
 * Builds card meta (excerpt + author) for a batch of documents.
 *
 * Reads MARKDOWN directly from b_note_document and strips it for the preview;
 * works uniformly for active, archived and trashed documents.
 */
final class DocumentCardMetaResolver
{
	public const DEFAULT_PREVIEW_MAX_LINES = 3;
	public const DEFAULT_PREVIEW_LINE_MAX_LEN = 160;

	// Cap raw MARKDOWN per row; SUBSTRING() counts characters (not bytes). Preview budget is
	// ~3*160 = 480 plain chars; 2048 chars covers ~4x markdown bloat (headings, links, fences).
	private const MARKDOWN_FETCH_LIMIT = 2048;

	public function __construct(
		private readonly MarkdownStripper $markdownStripper = new MarkdownStripper(),
		private readonly SnippetExtractor $snippetExtractor = new SnippetExtractor(),
		private readonly AuthorResolver $authorResolver = new AuthorResolver(),
	) {}

	/**
	 * @param array<int, int> $authorIdByDocumentId Map [documentId => authorUserId];
	 *   authorUserId is SystemUser::ID for system-authored documents (welcome content)
	 *   and a positive user id for regular ones. Negative ids are skipped.
	 * @return array<int, array{excerpt: string, author: ?array{id: int, name: string, photoUrl: ?string, color: string, isSystem?: true}}>
	 */
	public function resolve(
		array $authorIdByDocumentId,
		int $previewMaxLines = self::DEFAULT_PREVIEW_MAX_LINES,
		int $previewLineMaxLen = self::DEFAULT_PREVIEW_LINE_MAX_LEN,
	): array
	{
		if ($authorIdByDocumentId === [])
		{
			return [];
		}

		$documentIds = array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, array_keys($authorIdByDocumentId)),
			static fn(int $id): bool => $id > 0,
		)));
		if ($documentIds === [])
		{
			return [];
		}

		$bodies = $this->fetchBodies($documentIds);

		$authorIds = array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $authorIdByDocumentId),
			static fn(int $id): bool => $id > 0 || SystemUser::isSystem($id),
		)));
		$authors = $this->authorResolver->resolve($authorIds);

		$result = [];
		foreach ($authorIdByDocumentId as $docId => $authorId)
		{
			$documentId = (int)$docId;
			if ($documentId <= 0)
			{
				continue;
			}

			$body = $bodies[$documentId] ?? '';
			$excerpt = $body !== ''
				? $this->snippetExtractor->extractPreview($body, $previewMaxLines, $previewLineMaxLen)
				: ''
			;

			$normalizedAuthorId = (int)$authorId;
			$author = ($normalizedAuthorId > 0 || SystemUser::isSystem($normalizedAuthorId))
				? ($authors[$normalizedAuthorId] ?? null)
				: null
			;

			$result[$documentId] = [
				'excerpt' => $excerpt,
				'author' => $author,
			];
		}

		return $result;
	}

	/**
	 * Excerpt for a title/markdown pair already in memory, for callers that have just written the
	 * projection themselves. Goes through the same stripping, body shape and limits as the list path,
	 * so a card refreshed from here reads exactly as one rendered from the database.
	 */
	public function buildExcerpt(
		string $title,
		string $markdown,
		int $previewMaxLines = self::DEFAULT_PREVIEW_MAX_LINES,
		int $previewLineMaxLen = self::DEFAULT_PREVIEW_LINE_MAX_LEN,
	): string
	{
		$body = $this->composeBody($title, mb_substr($markdown, 0, self::MARKDOWN_FETCH_LIMIT));

		return $this->snippetExtractor->extractPreview($body, $previewMaxLines, $previewLineMaxLen);
	}

	/**
	 * @param int[] $documentIds
	 * @return array<int, string>
	 */
	private function fetchBodies(array $documentIds): array
	{
		$rows = DocumentTable::query()
			->registerRuntimeField(
				'MARKDOWN_HEAD',
				new ExpressionField(
					'MARKDOWN_HEAD',
					'SUBSTRING(%s, 1, ' . self::MARKDOWN_FETCH_LIMIT . ')',
					['MARKDOWN'],
				),
			)
			->setSelect(['ID', 'TITLE', 'MARKDOWN_HEAD'])
			->whereIn('ID', $documentIds)
			->fetchAll()
		;

		$out = [];
		foreach ($rows as $row)
		{
			$id = (int)($row['ID'] ?? 0);
			if ($id <= 0)
			{
				continue;
			}
			$out[$id] = $this->composeBody(
				(string)($row['TITLE'] ?? ''),
				(string)($row['MARKDOWN_HEAD'] ?? ''),
			);
		}

		return $out;
	}

	/**
	 * Builds the "title\n<stripped>" body shape SnippetExtractor::extractPreview expects.
	 */
	private function composeBody(string $title, string $markdownHead): string
	{
		$trimmedTitle = trim($title);
		$content = $this->markdownStripper->strip($markdownHead);

		return $trimmedTitle !== '' ? $trimmedTitle . "\n" . $content : $content;
	}
}
