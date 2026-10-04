<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\BizProc\Activity\Mixins;

/**
 * Resolves the actor of the event that fired the trigger for crm nodes declaring an initiator field.
 * Crm owns the resolver instead of relying on a helper in the bizproc base trigger.
 */
trait EventInitiatorTrait
{
	protected function resolveEventInitiatorUserId(): ?int
	{
		$fromPayload = $this->getEventData()['initiatorUserId'] ?? null;
		if ($fromPayload)
		{
			return (int)$fromPayload;
		}

		$targetUser = $this->getRootActivity()->{\CBPDocument::PARAM_TAGRET_USER};
		if (is_string($targetUser) && preg_match('/^user_(\d+)$/', $targetUser, $matches))
		{
			return (int)$matches[1];
		}

		return null;
	}
}
