<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Entity;

/**
 * The port ids a connection falls back to, by the convention of the zero index: o0 is the first output of a
 * block and i0 its first input. They apply only to a connection sent without ports (a linear chain where
 * sourcePortId/targetPortId are omitted). The number of ports of a block is not bounded in the general case
 * (the dynamic outputs of SwitchNode, say), and a port is addressed by the explicit id from
 * catalog.block.get: these constants are not a list of ports but the default of the zero index, one and the
 * same for the forward (AiAssistantDraftConverterService) and the reverse
 * (AiAssistantWorkflowTemplateConverterService) converter.
 */
final class AgentPortDefault
{
	public const SOURCE_PORT_ID = 'o0';
	public const TARGET_PORT_ID = 'i0';
}
