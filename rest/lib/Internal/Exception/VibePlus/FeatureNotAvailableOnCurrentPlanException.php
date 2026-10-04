<?php

declare(strict_types=1);

namespace Bitrix\Rest\Internal\Exception\VibePlus;

use Bitrix\Main\SystemException;

final class FeatureNotAvailableOnCurrentPlanException extends SystemException implements
	FeatureNotAvailableOnCurrentPlanExceptionInterface
{
	public function __construct(?\Throwable $previous = null)
	{
		parent::__construct(self::DEFAULT_MESSAGE, previous: $previous);
	}
}
