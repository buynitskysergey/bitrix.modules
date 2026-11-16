<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Command;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;
use Bitrix\Note\Internal\Service\RecycleBin\HardDeleteCollectionService;

/**
 * Removes one knowledge base for good. Offered on the page of a base opened from the recycle bin and
 * nowhere in the list of the bin: an irreversible action asks to first see what is going away.
 */
class HardDeleteCollectionCommand extends AbstractCommand
{
	// One request takes away one slice of the base. A base sent to the bin whole carries every
	// document it ever had, with its files and index entries, and that is far past what a single
	// request may hold: what is left keeps its place in the bin, the caller is told the base is
	// still there, and the cleanup agent takes away the rest on its own.
	private const BUDGET_SECONDS = 10.0;

	public function __construct(
		private readonly int $collectionId,
		private readonly HardDeleteCollectionService $hardDeleteCollectionService = new HardDeleteCollectionService(),
		private readonly float $budgetSeconds = self::BUDGET_SECONDS,
		// A selection of several bases shares one budget instead of granting each its own: the
		// request pays for one slice of work, whether it was asked for one base or for five.
		private readonly ?float $deadline = null,
	) {}

	/**
	 * @return Result data: deletedCount and completed - false when the budget ran out and the base
	 *         is still in the bin with part of its documents; a base that is not in the bin is
	 *         refused with COLLECTION_NOT_TRASHED and nothing of it is touched
	 */
	protected function execute(): Result
	{
		return $this->hardDeleteCollectionService->purge(
			$this->collectionId,
			$this->deadline ?? microtime(true) + $this->budgetSeconds,
		);
	}
}
