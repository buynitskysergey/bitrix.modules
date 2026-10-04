<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Enum;

/**
 * Machine readable class of a domain error of the agent graph.
 *
 * The code names a class of failures, not a single message: the graph validators produce dozens of texts,
 * and an external agent needs to branch on a handful of causes, not to match wording. It travels in
 * customData['errorCode'] of {@see \Bitrix\Main\Error}, next to the address of the problem in Error::code,
 * and reaches the agent as ValidationIssueDto::$code of the template.validate report - so the values here
 * are part of the public REST contract and must stay stable. The dry run is the only place it surfaces: a
 * refused write answers with the message and Error::code alone
 * ({@see \Bitrix\Rest\V3\Exception\Validation\ValidationException::output()} never reads customData).
 *
 * Failures no class has been extracted for carry no code at all: the address and the text are the whole
 * answer there, and inventing a class per message would freeze wording into the contract.
 */
enum GraphErrorCode: string
{
	/** The submitted block type is not in the catalog of the document type. */
	case BlockTypeUnknown = 'BlockTypeUnknown';

	/** The graph carries more blocks than the topology/layout engine accepts in a single save. */
	case BlockLimitExceeded = 'BlockLimitExceeded';

	/** The graph carries more connections than are accepted in a single save. */
	case ConnectionLimitExceeded = 'ConnectionLimitExceeded';

	/** The blocks of the graph carry more settings in total than are accepted in a single save. */
	case SettingLimitExceeded = 'SettingLimitExceeded';

	/** A single setting value is longer than is accepted in a single save. */
	case SettingValueLimitExceeded = 'SettingValueLimitExceeded';

	/** The submitted graph is larger, serialized, than is accepted in a single save. */
	case GraphSizeLimitExceeded = 'GraphSizeLimitExceeded';

	/** A connection or a complex-node rule references a port the block does not have. */
	case PortInvalid = 'PortInvalid';

	/** The body of a loop block is not connected back into its loop-back port. */
	case LoopNotClosed = 'LoopNotClosed';

	/** The membership of a frame overlay is broken: an unusable member, or a block captured without being one. */
	case FrameMembershipViolated = 'FrameMembershipViolated';

	/** Two frame overlays overlap after layout. */
	case FramesIntersect = 'FramesIntersect';
}
