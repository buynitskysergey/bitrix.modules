<?php

namespace Bitrix\Sign\Type\Api;

use Bitrix\Main;

/**
 * Error codes the portal produces on its own when the service answer was not received or could not
 * be read (see Service\ApiService). Unlike a service error code parsed from the response, such an
 * error leaves the call outcome unknown: the service may have applied the change anyway.
 */
enum TransportErrorCode: string
{
	case CLIENT_CONNECTION_ERROR = 'SIGN_CLIENT_CONNECTION_ERROR';
	case INCORRECT_JSON = 'INCORRECT_JSON';
	case INCORRECT_HTTP_STATUS = 'INCORRECT_HTTP_STATUS';
	case INCORRECT_DATA = 'INCORRECT_DATA';
	case INCORRECT_REGISTER_RESPONSE = 'INCORRECT_REGISTER_RESPONSE';

	/**
	 * @param Main\Error[] $errors
	 */
	public static function isPresentInErrors(array $errors): bool
	{
		foreach ($errors as $error)
		{
			if (self::tryFrom((string)$error->getCode()) !== null)
			{
				return true;
			}
		}

		return false;
	}
}
