<?php

declare(strict_types=1);

namespace Bitrix\Note\Public\Command;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Result;
use Bitrix\Note\Internal\Service\ExternalAccess\ExternalAccessLifecycleService;

/**
 * Drops the settings of a knowledge base whose link has never been enabled.
 *
 * A thin command per the write-path convention of the module; the rule "a record that worked at
 * least once is kept" belongs to the service. ACL stays in the controller.
 */
final class DiscardExternalAccessCommand extends AbstractCommand
{
	public function __construct(
		private readonly int $collectionId,
		private readonly ExternalAccessLifecycleService $service = new ExternalAccessLifecycleService(),
	) {}

	protected function execute(): Result
	{
		return $this->createResult(['success' => $this->service->discard($this->collectionId)]);
	}

	private function createResult(array $data = []): Result
	{
		$result = new Result();
		if (!empty($data))
		{
			$result->setData($data);
		}

		return $result;
	}
}
