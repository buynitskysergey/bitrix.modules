<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Command\RecordIntent;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Timeman\V2\Internal\DI\Container;

class RegisterShowCommand extends AbstractCommand
{
	public function __construct(
		public readonly int $userId,
		public readonly string $periodKey,
	)
	{
	}

	protected function execute(): Result
	{
		$result = new Result();

		$handler = Container::getInstance()->get(RegisterShowCommandHandler::class);

		try
		{
			return $handler($this);
		}
		catch (\Exception $e)
		{
			return $result->addError(Error::createFromThrowable($e));
		}
	}
}
