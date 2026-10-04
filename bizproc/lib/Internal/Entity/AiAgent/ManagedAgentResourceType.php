<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Entity\AiAgent;

/**
 * Type of a live resource owned by a managed system AI agent instance.
 *
 * Backing values are the tokens persisted in b_bp_managed_agent_resource.TYPE.
 */
enum ManagedAgentResourceType: string
{
	case Schedule = 'schedule';
	case Workflow = 'workflow';
	case BizprocBot = 'bizproc_bot';
	case OpenLinesBot = 'openlines_bot';
	case StorageScope = 'storage_scope';

	/**
	 * Order a removal walks the types in, which carries an invariant of its own: a schedule stops producing new
	 * work first, and the storage scope of the copy is released last, after every type that writes into it is
	 * gone. The order of the declarations above is free to change for any other reason, because this list and not
	 * {@see self::cases()} is what the removal follows.
	 *
	 * A type that is missing here would never be cleaned up, so a case of the removal states that the list covers
	 * every declared type exactly once.
	 *
	 * @return list<self>
	 */
	public static function cleanupOrder(): array
	{
		return [
			self::Schedule,
			self::Workflow,
			self::BizprocBot,
			self::OpenLinesBot,
			self::StorageScope,
		];
	}
}
