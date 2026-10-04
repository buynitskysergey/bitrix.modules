<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Enum;

/**
 * Origin of an agent graph write request, threaded through the shared validator and forward converter.
 *
 * The two transports of the AI-facing graph model (external REST agent and the internal Marta tool)
 * share one validation/conversion pipeline. This signal lets frame-specific branches in later phases
 * activate only for the REST agent, keeping Marta isolated from the frame overlay - the value is the
 * cross-cutting gate for those branches, not a pipeline fork.
 */
enum RequestSource: string
{
	case Rest = 'rest';
	case Marta = 'marta';
}
