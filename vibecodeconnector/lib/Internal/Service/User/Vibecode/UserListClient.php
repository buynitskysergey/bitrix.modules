<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\User\Vibecode;

use Bitrix\Main\Result;
use Bitrix\Vibecodeconnector\Internal\Service\Vibecode\AbstractMicroserviceClient;

final class UserListClient extends AbstractMicroserviceClient
{
	private const ACTION = 'user.list';

	public function fetch(): Result
	{
		return $this->performRequest(self::ACTION, []);
	}
}
