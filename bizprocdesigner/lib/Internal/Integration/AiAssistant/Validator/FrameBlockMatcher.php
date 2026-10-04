<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator;

/**
 * Single source of the frame-overlay identity predicate for the agent graph model.
 *
 * A frame is the presentational overlay the external REST agent uses to group blocks: it is not an
 * activity with ports/dialog but the EmptyBlockActivity carrying preset FRAME. The catalog advertises it
 * with a lowercased type (see {@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\AgentBlockCatalogService}),
 * so the type is matched case-insensitively while the preset id is matched exactly as advertised.
 *
 * Kept as one predicate so the block validator, the blocks validator, the connections validator and the
 * membership validator all recognise a frame identically.
 */
final class FrameBlockMatcher
{
	public const FRAME_BLOCK_TYPE = 'emptyblockactivity';
	public const FRAME_PRESET_ID = 'FRAME';

	public static function matches(mixed $type, mixed $presetId): bool
	{
		return is_string($type)
			&& mb_strtolower($type) === self::FRAME_BLOCK_TYPE
			&& $presetId === self::FRAME_PRESET_ID;
	}
}
