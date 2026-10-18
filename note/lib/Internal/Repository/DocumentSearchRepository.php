<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Repository;

use Bitrix\Main\Application;
use Bitrix\Main\ArgumentException;
use Bitrix\Main\DB\Ddl\DbType;
use Bitrix\Main\Entity\EntityInterface;
use Bitrix\Main\ObjectPropertyException;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Fields\FloatField;
use Bitrix\Main\ORM\Fields\IntegerField;
use Bitrix\Main\DB\SqlHelper;
use Bitrix\Main\ORM\Query\Query;
use Bitrix\Main\Repository\Exception\PersistenceException;
use Bitrix\Main\Repository\RepositoryInterface;
use Bitrix\Main\SystemException;
use Bitrix\Main\Text\Emoji;
use Bitrix\Main\Type\DateTime;
use Bitrix\Note\Internal\Access\Service\DocumentAccessService;
use Bitrix\Note\Internal\Entity\Search\SearchIndexEntry;
use Bitrix\Note\Internal\Entity\Search\SearchResultCollection;
use Bitrix\Note\Internal\Model\DocumentTable;
use Bitrix\Note\Internal\Model\NoteDocumentSearchTable;
use Bitrix\Note\Internal\Repository\Mapper\SearchIndexEntryMapper;
use Bitrix\Note\Internal\Repository\Mapper\SearchResultMapper;
use Bitrix\Note\Internal\Service\Search\SnippetExtractor;

class DocumentSearchRepository implements RepositoryInterface
{
	public function __construct(
		private readonly SnippetExtractor $snippetExtractor = new SnippetExtractor(),
	) {}

	public function getById(mixed $id): ?EntityInterface
	{
		$documentId = (int)$id;
		if ($documentId <= 0)
		{
			return null;
		}

		$row = NoteDocumentSearchTable::query()
			->setSelect(['DOCUMENT_ID', 'BODY'])
			->where('DOCUMENT_ID', $documentId)
			->fetch()
		;

		if ($row === false)
		{
			return null;
		}

		return SearchIndexEntryMapper::convertFromOrm($row);
	}

	/**
	 * @throws PersistenceException
	 */
	public function save(EntityInterface $entity): void
	{
		if (!$entity instanceof SearchIndexEntry)
		{
			throw new PersistenceException(
				'DocumentSearchRepository::save() accepts only ' . SearchIndexEntry::class,
			);
		}
		if ($entity->getDocumentId() <= 0)
		{
			throw new PersistenceException('SearchIndexEntry has invalid documentId');
		}

		$fields = SearchIndexEntryMapper::convertToOrm($entity);
		$fields['UPDATED_AT'] = new DateTime();

		try
		{
			NoteDocumentSearchTable::merge(
				$fields,
				[
					'BODY' => $fields['BODY'],
					'UPDATED_AT' => $fields['UPDATED_AT'],
				],
				['DOCUMENT_ID'],
			);
		}
		catch (\Exception $e)
		{
			throw new PersistenceException($e->getMessage(), $e);
		}
	}

	/**
	 * @throws PersistenceException
	 */
	public function delete(mixed $id): void
	{
		$documentId = (int)$id;
		if ($documentId <= 0)
		{
			return;
		}

		try
		{
			NoteDocumentSearchTable::deleteByFilter(['=DOCUMENT_ID' => $documentId]);
		}
		catch (\Exception $e)
		{
			throw new PersistenceException($e->getMessage(), $e);
		}
	}

	/**
	 * @param int[] $documentIds
	 * @throws PersistenceException
	 */
	public function deleteByIds(array $documentIds): void
	{
		$normalized = array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $documentIds),
			static fn(int $id): bool => $id > 0,
		)));
		if (empty($normalized))
		{
			return;
		}

		try
		{
			NoteDocumentSearchTable::deleteByFilter(['=DOCUMENT_ID' => $normalized]);
		}
		catch (\Exception $e)
		{
			throw new PersistenceException($e->getMessage(), $e);
		}
	}

	/**
	 * @param int[] $allowedCollectionIds
	 * @param string[] $snippetTokens Lowercase raw tokens used to build HTML snippets.
	 *                                When empty, snippets are not built.
	 * @param string[]|null $userAccessCodes Required when $hasDocumentGrants is true.
	 * @param bool $hasDocumentGrants When true, OR-extends ACL with document-level grants.
	 * @param string[] $titleBoostTokens Lowercase raw tokens used to push documents whose
	 *                                   title matches any of them above body-only matches.
	 *                                   When empty, results are ordered by SCORE only.
	 *
	 * @throws ArgumentException
	 * @throws ObjectPropertyException
	 * @throws SystemException
	 */
	public function searchByQuery(
		string $booleanQuery,
		array $allowedCollectionIds,
		int $limit,
		int $offset,
		array $snippetTokens = [],
		?array $userAccessCodes = null,
		bool $hasDocumentGrants = false,
		array $titleBoostTokens = [],
	): SearchResultCollection
	{
		$collection = new SearchResultCollection();

		if ($booleanQuery === '' || $limit <= 0)
		{
			return $collection;
		}

		// '*' is a collection policy, never a document grant - the listings drop it before building the
		// predicate, and search must drop it in exactly the same place.
		$codesPersonal = $hasDocumentGrants
			? DocumentAccessService::personalCodes($userAccessCodes ?? [])
			: [];

		// Fail-closed before the query rather than through an unsatisfiable predicate: a caller with
		// neither collections nor grants gets an empty answer, and the full-text query is not run at all.
		if (empty($allowedCollectionIds) && empty($codesPersonal))
		{
			return $collection;
		}

		$connection = Application::getConnection();
		$sqlHelper = $connection->getSqlHelper();
		$escapedQuery = $sqlHelper->forSql($booleanQuery);

		// PG: ts_rank gives a real float rank; MySQL MATCH AGAINST already returns relevance.
		if (DbType::getByConnectionType($connection->getType()) === DbType::PostgreSql)
		{
			$matchExpression = "ts_rank(to_tsvector('english', %s), to_tsquery('english', '{$escapedQuery}'))";
		}
		else
		{
			$matchExpression = $sqlHelper->getMatchFunction('%s', "'$escapedQuery'");
		}

		$withSnippets = $snippetTokens !== [];
		$select = [
			'DOCUMENT_ID',
			'DOC_TITLE' => 'DOCUMENT.TITLE',
			'DOC_COLLECTION_ID' => 'DOCUMENT.COLLECTION_ID',
		];
		if ($withSnippets)
		{
			$select[] = 'BODY';
		}

		$query = NoteDocumentSearchTable::query()
			->setSelect($select)
			->registerRuntimeField(
				'SCORE',
				(new ExpressionField('SCORE', $matchExpression, ['BODY']))
					->configureValueType(FloatField::class),
			)
			->addSelect('SCORE')
			->whereMatch('BODY', $booleanQuery)
			->where('DOCUMENT.IS_ARCHIVED', 'N')
			->where('DOCUMENT.IS_MAIN', DocumentTable::IS_MAIN_NO)
		;

		// The same predicate the listings are built on: search and lists answer one question - what is
		// visible to this user - and answering it twice is how the two drift apart.
		$query->where(DocumentAccessService::buildListVisibilityFilter(
			'DOCUMENT_ID',
			'DOCUMENT.COLLECTION_ID',
			$codesPersonal,
			$allowedCollectionIds,
		));

		$order = ['SCORE' => 'DESC', 'DOCUMENT_ID' => 'DESC'];
		if ($titleBoostTokens !== [])
		{
			$this->registerTitleMatchField($query, $sqlHelper, $titleBoostTokens);
			$order = ['TITLE_MATCH' => 'DESC'] + $order;
		}

		$rows = $query
			->setOrder($order)
			->setLimit($limit)
			->setOffset(max(0, $offset))
			->fetchAll()
		;

		foreach ($rows as $row)
		{
			$snippet = '';
			if ($withSnippets)
			{
				// BODY is stored emoji-encoded (see SearchIndexEntryMapper); decode so
				// snippets render real emoji instead of :hex: shortcodes.
				$snippet = $this->snippetExtractor->extract(
					Emoji::decode((string)($row['BODY'] ?? '')),
					$snippetTokens,
				);
			}
			$documentCollectionId = (int)($row['DOC_COLLECTION_ID'] ?? 0);
			// A row reachable only through a grant of its own: its collection is not among the ones the
			// caller may see, so the answer must not name it.
			$sharedAccess = !empty($codesPersonal)
				&& $documentCollectionId > 0
				&& !in_array($documentCollectionId, $allowedCollectionIds, true);
			$collection->add(SearchResultMapper::convertFromOrm($row, $snippet, $sharedAccess));
		}

		return $collection;
	}

	/**
	 * Registers a 0/1 runtime field that flags rows whose DOCUMENT.TITLE contains any of the
	 * boost tokens (substring match). Used as the primary ORDER BY key to lift title matches
	 * above body-only matches without changing the SCORE semantics.
	 *
	 * @param string[] $tokens Non-empty list of lowercase tokens.
	 */
	private function registerTitleMatchField(Query $query, SqlHelper $sqlHelper, array $tokens): void
	{
		$likeFragments = [];
		$buildFrom = [];
		foreach ($tokens as $token)
		{
			$token = (string)$token;
			if ($token === '')
			{
				continue;
			}
			// forSql escapes SQL quote/backslash. % and _ remain as LIKE wildcards — acceptable:
			// extractTokens splits by word characters, so wildcards should not appear, and even
			// if they did the worst case is a broader match, never an injection.
			$escaped = $sqlHelper->forSql($token);
			// getIlikeOperator(): PG → ILIKE (case-insensitive, no LOWER over column);
			// MySQL → LIKE (case-insensitive via ci collation, original behavior). Dialect-agnostic.
			// %% is the sprintf-escape for a literal % used by ExpressionField when it inlines
			// buildFrom field names into the expression template.
			$likeFragments[] = $sqlHelper->getIlikeOperator('%s', "'%%{$escaped}%%'");
			$buildFrom[] = 'DOCUMENT.TITLE';
		}

		if ($likeFragments === [])
		{
			return;
		}

		// CASE WHEN is cross-dialect; IF() is MySQL-only.
		$titleMatchSql = 'CASE WHEN (' . implode(' OR ', $likeFragments) . ') THEN 1 ELSE 0 END';

		$query->registerRuntimeField(
			'TITLE_MATCH',
			(new ExpressionField('TITLE_MATCH', $titleMatchSql, $buildFrom))
				->configureValueType(IntegerField::class),
		);
	}
}
