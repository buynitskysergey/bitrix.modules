<?php

declare(strict_types=1);

namespace Bitrix\Rest\Public\Command\Application\Embedding;

use Bitrix\Main;
use Bitrix\Rest\Internal\Entity\Application\App;

class DeleteEmbeddingCommand extends Main\Command\AbstractCommand
{
	public function __construct(
		public readonly App $app,
		public readonly int $userId,
		public readonly string $placement,
		public readonly ?string $handler,
		public readonly ?int $targetUserId,
	)
	{
	}

	protected function execute(): Main\Result
	{
		$result = new Main\Result();

		$result->setData([
			'deleted' => (new DeleteEmbeddingCommandHandler())($this),
		]);

		return $result;
	}
}
