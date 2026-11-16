<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Config\Feature;

use Bitrix\Main\Config\Feature\AbstractFlag;

/**
 * Opt-in for the AI entry points of the document editor. Default off. Read it ONLY through
 * EditorCopilotAvailability: the flag is the first of several conditions (the ai module, its
 * settings switch, the copilot access check), and a call site that checks the flag alone would
 * surface the entry points where they cannot work.
 */
final class AiEditorFlag extends AbstractFlag
{
	public function enabledByDefault(): bool
	{
		return false;
	}
}
