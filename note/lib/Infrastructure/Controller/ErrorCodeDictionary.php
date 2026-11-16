<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\Controller;

/**
 * Wire-level codes the controller layer puts on a refusal. The client tells a refusal apart from a
 * request failure by the value of the code, so the strings are normative and must not be renamed.
 * Constants live here only: neither a filter nor a controller keeps a literal of its own.
 */
final class ErrorCodeDictionary
{
	// ACL refusal. Same value as the one DocumentController already answers with.
	public const ACCESS_DENIED = 'NOTE_ACCESS_DENIED';

	// Tariff or tool gate: LicenseService::isAccessBlocked().
	public const LICENSE_BLOCKED = 'NOTE_LICENSE_BLOCKED';

	public const EXTERNAL_ACCESS_FORBIDDEN = 'NOTE_EXTERNAL_ACCESS_FORBIDDEN';
	public const EXTERNAL_ACCESS_INVALID_EXPIRES = 'NOTE_EXTERNAL_ACCESS_INVALID_EXPIRES';
	// The refusal of the public circuit deliberately glues six different reasons together, so that
	// the answer does not tell "there was no link" from "the link was closed". This is a different
	// meaning from the refusal of an employee by a right or a tariff, hence a separate name.
	public const EXTERNAL_ACCESS_DENIED = 'NOTE_EXTERNAL_ACCESS_DENIED';
	public const EXTERNAL_ACCESS_PASSWORD_REQUIRED = 'NOTE_EXTERNAL_ACCESS_PASSWORD_REQUIRED';
	public const EXTERNAL_ACCESS_NOT_FOUND = 'NOTE_EXTERNAL_ACCESS_NOT_FOUND';
	// The knowledge base behind the link holds more documents than one tree may carry.
	public const EXTERNAL_ACCESS_TREE_TOO_LARGE = 'NOTE_EXTERNAL_ACCESS_TREE_TOO_LARGE';
}
