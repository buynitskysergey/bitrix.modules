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
use Bitrix\Main\ORM\Query\Filter\ConditionTree;
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
	 * Which knowledge bases the query hits, and how much of each, over a bounded window of
	 * the ranking. Discovery and counting are the same pass: the window is walked anyway, so a
	 * counter per section would be a second walk of rows already in hand.
	 *
	 * The tally is what the window saw, and the window is what already decides which sections exist
	 * at all - a base whose hits all fall outside it has no section either way. So a count here can
	 * fall short of the whole corpus, and does so together with the section list, not against it.
	 *
	 * @param int[]|null $allowedCollectionIds null for a viewer whose reach is the whole portal - a
	 *        portal administrator. Standing in for that with a list of every base would put a growing
	 *        `IN (...)` into the query and, cut to a bound, would hide the bases past it.
	 * @param string[]|null $userAccessCodes
	 * @return array<int, int> collectionId => hits, 0 standing for hits reachable only through a
	 *         document grant
	 */
	public function tallyHitsByCollection(
		string $booleanQuery,
		?array $allowedCollectionIds,
		int $windowSize,
		?array $userAccessCodes = null,
		bool $hasDocumentGrants = false,
		array $titleBoostTokens = [],
	): array
	{
		if ($booleanQuery === '' || $windowSize <= 0)
		{
			return [];
		}

		$query = NoteDocumentSearchTable::query()
			->setSelect(['COLLECTION_ID' => 'DOCUMENT.COLLECTION_ID'])
			->registerRuntimeField(
				'SCORE',
				(new ExpressionField('SCORE', $this->buildMatchExpression($booleanQuery), ['BODY']))
					->configureValueType(FloatField::class),
			)
			->addSelect('SCORE')
			->whereMatch('BODY', $booleanQuery)
			->where('DOCUMENT.IS_ARCHIVED', 'N')
			->where('DOCUMENT.IS_MAIN', DocumentTable::IS_MAIN_NO)
			// The window is read in the order the results themselves are read in. Unordered, which
			// rows a LIMIT returns is the database's own business, so past the window it was chance
			// that decided which bases got a section at all - and a base holding the best hit of the
			// query could go missing along with everything the viewer could have reached through it.
			->setLimit($windowSize)
		;

		// Down to the title boost: the window is the head of the same ranking the pages are cut from,
		// so a base is offered a section exactly when one of the first rows of the answer belongs to it.
		$order = ['SCORE' => 'DESC', 'DOCUMENT_ID' => 'DESC'];
		if ($titleBoostTokens !== [])
		{
			$this->registerTitleMatchField($query, Application::getConnection()->getSqlHelper(), $titleBoostTokens);
			$order = ['TITLE_MATCH' => 'DESC'] + $order;
		}
		$query->setOrder($order);

		// The same gate the ranked reads carry: an archived or gone base is off every live surface,
		// and its documents stay indexed - without this the search draws a section for a base that
		// is not there, and that section takes the place of a real one in the fifty the answer holds.
		$stateGate = DocumentAccessService::buildCollectionStateFilter('DOCUMENT.COLLECTION_ID', []);
		if ($stateGate !== null)
		{
			$query->where($stateGate);
		}

		$canUseDocumentGrants = $hasDocumentGrants && !empty($userAccessCodes);
		if ($canUseDocumentGrants)
		{
			$query->where($this->buildAclCondition($allowedCollectionIds ?? [], $userAccessCodes));
		}
		elseif ($allowedCollectionIds === null)
		{
			// The whole portal: nothing to narrow by.
		}
		elseif (!empty($allowedCollectionIds))
		{
			$query->whereIn('DOCUMENT.COLLECTION_ID', $allowedCollectionIds);
		}
		else
		{
			return [];
		}

		$allowed = $allowedCollectionIds === null ? null : array_flip($allowedCollectionIds);
		$out = [];
		foreach ($query->fetchAll() as $row)
		{
			$collectionId = (int)($row['COLLECTION_ID'] ?? 0);
			// A hit in a base the viewer cannot see as a whole has no name to show; it joins the
			// technical section instead of naming somebody else's knowledge base.
			$key = ($allowed === null || isset($allowed[$collectionId])) ? $collectionId : 0;
			$out[$key] = ($out[$key] ?? 0) + 1;
		}

		return $out;
	}

	/**
	 * Scopes a search query to one section. A positive collection id narrows to that knowledge base;
	 * zero means the technical section - hits reachable only through a document grant, whose
	 * knowledge base has no name to show the viewer.
	 *
	 * @param int[]|null $allowedCollectionIds null is the whole portal: every base is the viewer's own,
	 *        so no section of theirs is reachable per document only.
	 * @param string[]|null $userAccessCodes
	 */
	private function applySectionScope(
		Query $query,
		int $collectionId,
		?array $allowedCollectionIds,
		?array $userAccessCodes,
		bool $hasDocumentGrants,
	): void
	{
		// Same gate as in the flat ranking: a section is scoped by a grant just as often, and
		// a grant alone must not carry a document out of an archived or gone base.
		$stateGate = DocumentAccessService::buildCollectionStateFilter('DOCUMENT.COLLECTION_ID', []);
		if ($stateGate !== null)
		{
			$query->where($stateGate);
		}

		if ($collectionId > 0)
		{
			$query->where('DOCUMENT.COLLECTION_ID', $collectionId);

			if ($allowedCollectionIds !== null && !in_array($collectionId, $allowedCollectionIds, true))
			{
				// Reachable only per document: the section exists for the viewer, the knowledge base
				// as a whole does not.
				$query->where(DocumentAccessService::buildDocumentGrantFilter(
					'DOCUMENT_ID',
					DocumentAccessService::personalCodes($userAccessCodes ?? []),
				));
			}

			return;
		}

		if (!$hasDocumentGrants || empty($userAccessCodes))
		{
			// Deliberately unsatisfiable: without personal codes the technical section has no members.
			$query->where('DOCUMENT_ID', '<', 0);

			return;
		}

		$query->where(DocumentAccessService::buildDocumentGrantFilter(
			'DOCUMENT_ID',
			DocumentAccessService::personalCodes($userAccessCodes),
		));
		if (!empty($allowedCollectionIds))
		{
			$query->whereNotIn('DOCUMENT.COLLECTION_ID', $allowedCollectionIds);
		}
	}

	/**
	 * One page inside a section. The two-phase shape is what stops a knowledge base from
	 * landing on the first page and again on the third: the page is asked for by section, not sliced
	 * off a single flat ranking.
	 *
	 * @param int[]|null $allowedCollectionIds null is the whole portal (see tallyHitsByCollection)
	 * @param string[] $snippetTokens
	 * @param string[]|null $userAccessCodes
	 * @param string[] $titleBoostTokens
	 */
	public function searchInSection(
		string $booleanQuery,
		int $collectionId,
		int $limit,
		int $offset,
		?array $allowedCollectionIds,
		array $snippetTokens = [],
		?array $userAccessCodes = null,
		bool $hasDocumentGrants = false,
		array $titleBoostTokens = [],
	): SearchResultCollection
	{
		return $this->runRanked(
			booleanQuery: $booleanQuery,
			limit: $limit,
			offset: $offset,
			allowedCollectionIds: $allowedCollectionIds,
			snippetTokens: $snippetTokens,
			titleBoostTokens: $titleBoostTokens,
			applyScope: function (Query $query) use (
				$collectionId,
				$allowedCollectionIds,
				$userAccessCodes,
				$hasDocumentGrants,
			): bool {
				$this->applySectionScope($query, $collectionId, $allowedCollectionIds, $userAccessCodes, $hasDocumentGrants);

				return true;
			},
		);
	}

	/**
	 * One ranked window over every section the viewer may see, in the same order a section page uses.
	 * The first page of several sections at once is cut out of it, so opening the results costs one
	 * ranking read instead of one per knowledge base found.
	 *
	 * The two sets of bases here answer different questions and neither stands in for the other:
	 * $allowedCollectionIds is the reach of the reader and gates the rows, $sectionCollectionIds is
	 * what the caller asked about and narrows them. Left wide, the window is spent on the ranking of
	 * bases nobody asked about, and a base that was asked about drops out of the answer with hits of
	 * its own - which the caller then has to go and read one by one.
	 *
	 * @param int[]|null $allowedCollectionIds null is the whole portal (see tallyHitsByCollection)
	 * @param int[]|null $sectionCollectionIds null asks the ranking to stay as wide as the reach
	 * @param string[] $snippetTokens
	 * @param string[]|null $userAccessCodes
	 * @param string[] $titleBoostTokens
	 */
	public function searchAcrossSections(
		string $booleanQuery,
		int $limit,
		?array $allowedCollectionIds,
		array $snippetTokens = [],
		?array $userAccessCodes = null,
		bool $hasDocumentGrants = false,
		array $titleBoostTokens = [],
		?array $sectionCollectionIds = null,
	): SearchResultCollection
	{
		return $this->runRanked(
			booleanQuery: $booleanQuery,
			limit: $limit,
			offset: 0,
			allowedCollectionIds: $allowedCollectionIds,
			snippetTokens: $snippetTokens,
			titleBoostTokens: $titleBoostTokens,
			applyScope: function (Query $query) use (
				$allowedCollectionIds,
				$userAccessCodes,
				$hasDocumentGrants,
				$sectionCollectionIds,
			): bool {
				$stateGate = DocumentAccessService::buildCollectionStateFilter('DOCUMENT.COLLECTION_ID', []);
				if ($stateGate !== null)
				{
					$query->where($stateGate);
				}

				// Narrowed before the gate and independently of it: asking about no base at all is an
				// empty answer, not a portal-wide read.
				if ($sectionCollectionIds !== null)
				{
					if (empty($sectionCollectionIds))
					{
						return false;
					}

					$query->whereIn('DOCUMENT.COLLECTION_ID', $sectionCollectionIds);
				}

				if ($hasDocumentGrants && !empty($userAccessCodes))
				{
					$query->where($this->buildAclCondition($allowedCollectionIds ?? [], $userAccessCodes));

					return true;
				}

				// The whole portal: the ranking is read as it stands, with nothing to narrow it by.
				if ($allowedCollectionIds === null)
				{
					return true;
				}

				if (empty($allowedCollectionIds))
				{
					return false;
				}

				$query->whereIn('DOCUMENT.COLLECTION_ID', $allowedCollectionIds);

				return true;
			},
		);
	}

	/**
	 * Snippets for rows already chosen, read apart from the ranking.
	 *
	 * A window read for several sections at once asks for more rows than it keeps - the ranking is
	 * portal-wide and a base may hold none of the top of it - and carrying `BODY` through that window
	 * meant reading the texts of documents the answer then dropped. The texts of `mediumtext` size,
	 * decoded once more per row on the way to the snippet. So the window goes without them and the
	 * bodies are read here, for the rows that survived the split.
	 *
	 * @param int[] $documentIds
	 * @param string[] $snippetTokens
	 * @return array<int, string> documentId => snippet, only for rows that have one
	 */
	public function fetchSnippets(array $documentIds, array $snippetTokens): array
	{
		$ids = array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $documentIds),
			static fn(int $id): bool => $id > 0,
		)));
		if (empty($ids) || empty($snippetTokens))
		{
			return [];
		}

		$out = [];
		foreach (array_chunk($ids, 200) as $chunk)
		{
			$rows = NoteDocumentSearchTable::query()
				->setSelect(['DOCUMENT_ID', 'BODY'])
				->whereIn('DOCUMENT_ID', $chunk)
				->fetchAll()
			;
			foreach ($rows as $row)
			{
				$snippet = $this->snippetExtractor->extract(
					Emoji::decode((string)($row['BODY'] ?? '')),
					$snippetTokens,
				);
				if ($snippet !== '')
				{
					$out[(int)$row['DOCUMENT_ID']] = $snippet;
				}
			}
		}

		return $out;
	}

	/**
	 * @param int[]|null $allowedCollectionIds null is the whole portal (see tallyHitsByCollection)
	 * @param string[] $snippetTokens
	 * @param string[] $titleBoostTokens
	 * @param callable(Query): bool $applyScope false when the scope cannot match anything
	 */
	/**
	 * How well a row answers the query, in the dialect of the database under it. One expression for
	 * the ranked reads and for the window that decides which bases they are read from: ordered by
	 * anything else, the window would not be the beginning of the same list.
	 */
	private function buildMatchExpression(string $booleanQuery): string
	{
		$connection = Application::getConnection();
		$sqlHelper = $connection->getSqlHelper();
		$escapedQuery = $sqlHelper->forSql($booleanQuery);

		if (DbType::getByConnectionType($connection->getType()) === DbType::PostgreSql)
		{
			return "ts_rank(to_tsvector('english', %s), to_tsquery('english', '{$escapedQuery}'))";
		}

		return $sqlHelper->getMatchFunction('%s', "'$escapedQuery'");
	}

	private function runRanked(
		string $booleanQuery,
		int $limit,
		int $offset,
		?array $allowedCollectionIds,
		array $snippetTokens,
		array $titleBoostTokens,
		callable $applyScope,
	): SearchResultCollection
	{
		$collection = new SearchResultCollection();
		if ($booleanQuery === '' || $limit <= 0)
		{
			return $collection;
		}

		$sqlHelper = Application::getConnection()->getSqlHelper();
		$matchExpression = $this->buildMatchExpression($booleanQuery);

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

		if (!$applyScope($query))
		{
			return $collection;
		}

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
				$snippet = $this->snippetExtractor->extract(
					Emoji::decode((string)($row['BODY'] ?? '')),
					$snippetTokens,
				);
			}
			$documentCollectionId = (int)($row['DOC_COLLECTION_ID'] ?? 0);
			// A viewer whose reach is the whole portal shares nothing with anybody: every base is theirs.
			$sharedAccess = $allowedCollectionIds !== null
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

	/**
	 * The OR-condition of the sectioned reads: a document is visible if it lives in an accessible
	 * collection, or the caller holds a positive grant on the document itself. Deny is scoped to
	 * explicit rows only, so inheritance stays hard; the grant predicate comes from
	 * {@see DocumentAccessService::buildDocumentGrantFilter()}.
	 *
	 * The state of the base is NOT part of it: the sectioned reads gate the whole query by
	 * {@see DocumentAccessService::buildCollectionStateFilter()} before they branch, so every branch
	 * carries it, not just this one.
	 *
	 * @param int[] $allowedCollectionIds
	 * @param string[] $accessCodes
	 */
	private function buildAclCondition(array $allowedCollectionIds, array $accessCodes): ConditionTree
	{
		$codesPersonal = DocumentAccessService::personalCodes($accessCodes);

		$tree = new ConditionTree();
		$tree->logic('or');

		if (!empty($allowedCollectionIds))
		{
			$tree->whereIn('DOCUMENT.COLLECTION_ID', $allowedCollectionIds);
		}

		if (!empty($codesPersonal))
		{
			$tree->where(DocumentAccessService::buildDocumentGrantFilter('DOCUMENT_ID', $codesPersonal));
		}

		return $tree;
	}
}
