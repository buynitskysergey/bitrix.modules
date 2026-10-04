<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Public\Command\RecordIntent;

use Bitrix\Main\Command\AbstractCommand;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Main\Validation\Rule\InArray;
use Bitrix\Timeman\V2\Internal\DI\Container;

class RegisterOutcomeCommand extends AbstractCommand
{
	public const OUTCOME_DISMISS = 'dismiss';
	public const OUTCOME_TARGET_ACTION = 'targetAction';

	private const ALLOWED_OUTCOMES = [
		self::OUTCOME_DISMISS,
		self::OUTCOME_TARGET_ACTION,
	];

	public function __construct(
		public readonly int $userId,
		public readonly string $periodKey,
		#[InArray(self::ALLOWED_OUTCOMES, true)]
		public readonly string $outcome,
	)
	{
	}

	protected function execute(): Result
	{
		$result = new Result();

		$handler = Container::getInstance()->get(RegisterOutcomeCommandHandler::class);

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
