<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Exception\Pilot;

/**
 * The membership in a pilot audience could not be established: the platform did not give the access
 * codes of the employee, or the storage of the audience did not answer.
 *
 * The refusal is a value of its own and never collapses into an empty audience: a reader that took it
 * for "not a participant" would show a template published to a pilot only to everyone.
 */
class AudienceUnavailableException extends \Exception
{
	public static function accessCodesUnavailable(int $userId, \Throwable $cause): self
	{
		return new self("Access codes of the user {$userId} are unavailable", 0, $cause);
	}

	public static function audienceStorageUnavailable(\Throwable $cause): self
	{
		return new self('The pilot audience storage is unavailable', 0, $cause);
	}
}
