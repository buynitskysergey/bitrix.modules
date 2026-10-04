<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Exception\DataView;

use Bitrix\Bizproc\Internal\Exception\Exception;

class CorruptedDataViewStateException extends Exception
{
	public function __construct(string $fieldName, string $reason = '')
	{
		$message = 'Corrupted data view field ' . $fieldName;
		if ($reason !== '')
		{
			$message .= ': ' . $reason;
		}

		parent::__construct(
			message: $message,
			code: self::CODE_DATA_VIEW_CORRUPTED_STATE,
		);
	}
}
