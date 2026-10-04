<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Exception\DataView;

use Bitrix\Bizproc\Internal\Exception\Exception;

class SaveDataViewException extends Exception
{
	public function __construct($message = '')
	{
		$message = $message === '' ? 'Failed saving data view definition' : $message;
		$code = self::CODE_DATA_VIEW_SAVE;

		if (str_contains($message, 'Duplicate entry') || str_contains($message, 'duplicate key'))
		{
			$message = 'A data view definition already exists for this storage type.';
		}

		parent::__construct(
			message: $message,
			code: $code,
		);
	}
}
