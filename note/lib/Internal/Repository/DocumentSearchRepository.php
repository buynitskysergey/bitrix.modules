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

		$canUseDocumentGrants = $hasDocumentGrants && !empty($userAccessCodes);
		if (empty($allowedCollectionIds) && !$canUseDocumentGrants)
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

		if ($canUseDocumentGrants)
		{
			$query->where($this->buildAclCondition($allowedCollectionIds, $userAccessCodes));
		}
		else
		{
			$query->whereIn('DOCUMENT.COLLECTION_ID', $allowedCollectionIds);
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
				// BODY is stored emoji-encoded (see SearchIndexEntryMapper); decode so
				// snippets render real emoji instead of :hex: shortcodes.
				$snippet = $this->snippetExtractor->extract(
					Emoji::decode((string)($row['BODY'] ?? '')),
					$snippetTokens,
				);
			}
			$documentCollectionId = (int)($row['DOC_COLLECTION_ID'] ?? 0);
			$sharedAccess = $canUseDocumentGrants
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
	 * [ALG-02] Builds the OR-condition: document is visible if it lives in an accessible
	 * collection, OR the current user holds an inherited positive grant (source != 0, VIEW+)
	 * unconditionally, OR an explicit positive grant (source = 0, VIEW+) with no explicit
	 * NONE override. Deny is scoped to explicit rows only, so inheritance stays hard. The grant
	 * predicate itself comes from DocumentAccessService::buildDocumentGrantFilter.
	 *
	 * @param int[] $allowedCollectionIds
	 * @param string[] $accessCodes
	 */
	private function buildAclCondition(array $allowedCollectionIds, array $accessCodes): ConditionTree
	{
		$codesPersonal = array_values(array_unique(array_filter(
			$accessCodes,
			static fn($code) => is_string($code) && $code !== '' && $code !== '*',
		)));

		$tree = new ConditionTree();
		$tree->logic('or');

		if (!empty($allowedCollectionIds))
		{
			$tree->whereIn('DOCUMENT.COLLECTION_ID', $allowedCollectionIds);
		}

		if (!empty($codesPersonal))
		{
			// Taken from the predicate's owner rather than restated here: search must never diverge
			// from what the listings show.
			$tree->where(DocumentAccessService::buildDocumentGrantFilter('DOCUMENT_ID', $codesPersonal));
		}

		return $tree;
	}
}
