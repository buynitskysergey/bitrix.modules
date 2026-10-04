<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Command\AiAgent\Dto;

/**
 * External object a managed system AI agent instance belongs to.
 *
 * Together with the system code these three fields form the immutable part of the logical identity of the
 * instance: they are hashed into its identity hash and are never updated for an already created instance.
 *
 * The DTO is a carrier and accepts any string, so that a malformed call is answered by the command with a
 * stable error code instead of an exception thrown while the caller is still building its arguments. The
 * constraints below are enforced by the module while it builds the identity:
 *
 * <ul>
 * <li> namespace: lowercase [a-z0-9._-] token, 1 to 64 characters, for example socialnetwork
 * <li> type: lowercase [a-z0-9._-] token, 1 to 64 characters, for example project
 * <li> id: 1 to 128 characters of UTF-8 without control characters, stable for the object
 * </ul>
 */
final class SystemAiAgentContext
{
	public function __construct(
		public readonly string $namespace,
		public readonly string $type,
		public readonly string $id,
	)
	{
	}
}
