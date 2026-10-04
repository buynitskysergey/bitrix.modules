<?php

namespace Bitrix\Main\Infrastructure\Rest\Exception;

use Bitrix\Main\SystemException;

final class InsufficientScopeException extends SystemException
{
	public function __construct(public readonly string $scope)
	{
		parent::__construct("The current method required more scopes. ({$scope})");
	}
}
