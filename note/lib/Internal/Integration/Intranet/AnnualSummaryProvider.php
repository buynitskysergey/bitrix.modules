<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Integration\Intranet;

use Bitrix\Intranet\Public\Provider\AnnualSummary\SummaryProviderInterface;
use Bitrix\Main\Entity\EntityCollection;
use Bitrix\Main\Type\Date;
use Bitrix\Note\Internal\Repository\CollectionRepository;
use Bitrix\Note\Internal\Repository\DocumentRepository;

/**
 * Note's contribution to the annual summary: three numbers per employee for the period the
 * intranet agent hands over. "Provider" here is intranet's word - this is an implementation of a
 * foreign interface, not one of note's own read providers, which is why it lives in the
 * integration layer instead of the public API of the module.
 */
final class AnnualSummaryProvider implements SummaryProviderInterface
{
	public const ID = 'note.annual_summary';

	public const METRIC_CREATED_COLLECTIONS = 'note.created_collections';
	public const METRIC_CREATED_DOCUMENTS = 'note.created_documents';
	public const METRIC_DOCUMENT_READS = 'note.document_reads';

	/**
	 * [Q-1] Users per batch. Batch aggregation makes small batches pointless - every batch costs
	 * one full scan of the collection table, which carries no by-author index on purpose - while
	 * an oversized IN list can push the MySQL 5.6 planner off the document index.
	 */
	private const USER_ID_LIMIT = 50;

	private int $lastUserId = 0;

	public function __construct(
		private readonly Date $from,
		private readonly Date $to,
		private readonly ?CollectionRepository $collectionRepository = null,
		private readonly ?DocumentRepository $documentRepository = null,
	)
	{
	}

	public function getId(): string
	{
		return self::ID;
	}

	public function getLastUserId(): int
	{
		return $this->lastUserId;
	}

	public function getUserIdLimit(): int
	{
		return self::USER_ID_LIMIT;
	}

	/**
	 * @param int[] $userIds
	 */
	public function provide(array $userIds): EntityCollection
	{
		$normalizedIds = array_values(array_unique(array_filter(
			array_map(static fn($id): int => (int)$id, $userIds),
			static fn(int $id): bool => $id > 0,
		)));

		// The cursor is shared by every provider of the agent, and the agent moves on to the next
		// provider on the very step that returns an incomplete batch - it never calls us again with
		// an empty list. So any incomplete batch resets the cursor, not just an empty one: left at
		// the last id it would hand the next provider in the queue a query past the end of the user
		// list, that provider would get an empty batch and be skipped whole.
		//
		// The cursor is set before the counting below and regardless of its outcome: a miscount loses
		// the numbers of one batch, but a cursor left standing stops the annual summary of the whole
		// portal.
		$isLastBatch = count($userIds) < self::USER_ID_LIMIT;
		$this->lastUserId = ($isLastBatch || empty($normalizedIds)) ? 0 : max($normalizedIds);

		if (empty($normalizedIds))
		{
			return new EntityCollection();
		}

		return new EntityCollection(...$this->collect($normalizedIds));
	}

	/**
	 * @param int[] $userIds
	 * @return AnnualSummaryValue[]
	 */
	private function collect(array $userIds): array
	{
		try
		{
			$metrics = [
				self::METRIC_CREATED_COLLECTIONS => $this->collectionRepository()
					->countCreatedByAuthors($userIds, $this->from, $this->to),
				self::METRIC_CREATED_DOCUMENTS => $this->documentRepository()
					->countCreatedByAuthors($userIds, $this->from, $this->to),
				self::METRIC_DOCUMENT_READS => $this->documentRepository()
					->countReadsByAuthors($userIds, $this->from, $this->to),
			];
		}
		catch (\Throwable $exception)
		{
			// A provider that throws takes the whole step of the agent down, metrics of other
			// modules included - so the failure stays here, in the log. The batch bounds and the
			// period are part of the message on purpose: the cursor has already moved past this
			// batch, so this record is the only way to tell whose numbers are missing and to
			// recount them by hand.
			AddMessage2Log(sprintf(
				'note annual summary: failed to count metrics for %d users (ids %d..%d, period %s..%s): %s',
				count($userIds),
				min($userIds),
				max($userIds),
				$this->from->format('Y-m-d'),
				$this->to->format('Y-m-d'),
				$exception->getMessage(),
			), 'note');

			return [];
		}

		$values = [];
		foreach ($metrics as $name => $countsByUser)
		{
			foreach ($countsByUser as $userId => $total)
			{
				if ($total > 0)
				{
					$values[] = new AnnualSummaryValue((int)$userId, $name, (int)$total);
				}
			}
		}

		return $values;
	}

	private function collectionRepository(): CollectionRepository
	{
		return $this->collectionRepository ?? new CollectionRepository();
	}

	private function documentRepository(): DocumentRepository
	{
		return $this->documentRepository ?? new DocumentRepository();
	}
}
