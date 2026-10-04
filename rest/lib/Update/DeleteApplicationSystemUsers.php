<?php

declare(strict_types=1);

namespace Bitrix\Rest\Update;

use Bitrix\Main\Update\Stepper;

final class DeleteApplicationSystemUsers extends Stepper
{
	protected static $moduleId = 'rest';

	public function execute(array &$option): bool
	{
		return self::FINISH_EXECUTION;
	}
}
