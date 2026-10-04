<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service;

use Bitrix\Bizproc\Internal\AI\Agent\Generator\TemplateBuilder\ActivityRegistry;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentBlock;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentBlockCollection;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity\AgentConnectionCollection;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Enum\GraphErrorCode;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Error\GraphError;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Layout\AgentBlockGeometry;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Layout\FrameGeometry;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Layout\TopologyLayoutEngine;
use Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Validator\FrameBlockMatcher;
use Bitrix\Main\Result;

/**
 * Stateless post-layout geometric check of frame overlays (ALG-03), shared by the REST write-path
 * (template.draft.add) and its dry-run (template.validate) so both verdicts are identical by construction
 * rather than by duplicated code.
 *
 * It replays the same deterministic {@see TopologyLayoutEngine} the forward converter runs and derives each
 * frame's bounding box with the same {@see FrameGeometry} primitive, so the geometry it validates is exactly
 * the geometry that will be persisted. It rejects the two geometric violations the membership validator
 * cannot see:
 *   - a non-member, non-frame block whose centre falls inside a frame (visual capture of a foreign block);
 *   - two frames whose rectangles intersect.
 *
 * Every error is anchored at blocks.&lt;frameIndex&gt; so the REST error mapper
 * ({@see \Bitrix\BizprocDesigner\Infrastructure\Rest\Service\GraphValidationErrorMapper})
 * recovers the offending frame's blockId. The check is a no-op for a graph without frames.
 */
final class FramePostLayoutChecker
{
	private readonly TopologyLayoutEngine $layoutEngine;
	private readonly ActivityRegistry $activityRegistry;

	public function __construct(?TopologyLayoutEngine $layoutEngine = null, ?ActivityRegistry $activityRegistry = null)
	{
		$this->layoutEngine = $layoutEngine ?? new TopologyLayoutEngine();
		$this->activityRegistry = $activityRegistry ?? new ActivityRegistry();
	}

	public function check(
		AgentBlockCollection $blocks,
		AgentConnectionCollection $connections,
		string $path = 'blocks',
	): Result
	{
		$result = new Result();

		$frames = $this->collectFrames($blocks);
		if ($frames === [])
		{
			return $result;
		}

		$positions = $this->layoutEngine->compute($blocks, $connections);
		$blocksById = $this->indexById($blocks);

		foreach ($frames as $index => $frame)
		{
			$frames[$index]['rect'] = FrameGeometry::boundingBox(
				$this->memberRects($frame['memberIds'], $positions, $blocksById),
			);
			$frames[$index]['memberSet'] = array_fill_keys($frame['memberIds'], true);
		}

		$frameIds = [];
		foreach ($frames as $frame)
		{
			$frameIds[$frame['id']] = true;
		}

		$this->addCaptureErrors($result, $frames, $frameIds, $blocks, $positions, $blocksById, $path);
		$this->addIntersectionErrors($result, $frames, $path);

		return $result;
	}

	/**
	 * (a) A frame must not capture a foreign block: no non-member, non-frame block's centre may lie inside it.
	 *
	 * @param list<array{index: int, id: string, memberIds: list<string>, rect: array{x: int, y: int, width: int, height: int}, memberSet: array<string, true>}> $frames
	 * @param array<string, true> $frameIds
	 * @param array<string, array{x: int, y: int}> $positions
	 * @param array<string, AgentBlock> $blocksById
	 */
	private function addCaptureErrors(
		Result $result,
		array $frames,
		array $frameIds,
		AgentBlockCollection $blocks,
		array $positions,
		array $blocksById,
		string $path,
	): void
	{
		foreach ($frames as $frame)
		{
			foreach ($blocks as $block)
			{
				if (isset($frameIds[$block->id]) || isset($frame['memberSet'][$block->id]))
				{
					continue;
				}

				$position = $positions[$block->id] ?? null;
				if ($position === null)
				{
					continue;
				}

				$size = AgentBlockGeometry::hitTestSizeForType($block->type, $this->activityRegistry);
				if (FrameGeometry::contains(
					$frame['rect'],
					(int)$position['x'],
					(int)$position['y'],
					$size['width'],
					$size['height'],
				))
				{
					$framePath = "{$path}.{$frame['index']}";
					$result->addError(GraphError::at(
						$framePath,
						"{$framePath}: block '{$block->id}' is captured by frame '{$frame['id']}' but is not its member",
						GraphErrorCode::FrameMembershipViolated,
					));
				}
			}
		}
	}

	/**
	 * (b) Frames must not intersect: any two frame rectangles that overlap are rejected.
	 *
	 * @param list<array{index: int, id: string, rect: array{x: int, y: int, width: int, height: int}}> $frames
	 */
	private function addIntersectionErrors(Result $result, array $frames, string $path): void
	{
		$count = count($frames);
		for ($a = 0; $a < $count; $a++)
		{
			for ($b = $a + 1; $b < $count; $b++)
			{
				if (FrameGeometry::rectsOverlap($frames[$a]['rect'], $frames[$b]['rect']))
				{
					$framePath = "{$path}.{$frames[$a]['index']}";
					$result->addError(GraphError::at(
						$framePath,
						"{$framePath}: frames '{$frames[$a]['id']}' and '{$frames[$b]['id']}' intersect",
						GraphErrorCode::FramesIntersect,
					));
				}
			}
		}
	}

	/**
	 * Frames in the graph, in input order, so the index matches the request blocks.&lt;index&gt; position.
	 *
	 * @return list<array{index: int, id: string, memberIds: list<string>}>
	 */
	private function collectFrames(AgentBlockCollection $blocks): array
	{
		$frames = [];
		$index = 0;
		foreach ($blocks as $block)
		{
			if ($block instanceof AgentBlock && FrameBlockMatcher::matches($block->type, $block->presetId))
			{
				$frames[] = [
					'index' => $index,
					'id' => $block->id,
					'memberIds' => $block->memberBlockIds,
				];
			}

			$index++;
		}

		return $frames;
	}

	/**
	 * Member rectangles in layout space: each member's position (keyed by the raw agent block id the layout
	 * emits, never the converted activity id) sized by its real widget type ({@see AgentBlockGeometry::measure}),
	 * so the checked bounding box equals the one the forward converter persists. Members with an unknown
	 * position or block are skipped.
	 *
	 * @param list<string> $memberIds
	 * @param array<string, array{x: int, y: int}> $positions
	 * @param array<string, AgentBlock> $blocksById
	 * @return list<array{x: int, y: int, width: int, height: int}>
	 */
	private function memberRects(array $memberIds, array $positions, array $blocksById): array
	{
		$memberRects = [];
		foreach ($memberIds as $memberId)
		{
			if (!isset($positions[$memberId], $blocksById[$memberId]))
			{
				continue;
			}

			$size = AgentBlockGeometry::measure($blocksById[$memberId], $this->activityRegistry);
			$memberRects[] = [
				'x' => $positions[$memberId]['x'],
				'y' => $positions[$memberId]['y'],
				'width' => $size['width'],
				'height' => $size['height'],
			];
		}

		return $memberRects;
	}

	/**
	 * Indexes the agent blocks by their raw id, so members and captured blocks can be sized by type.
	 *
	 * @return array<string, AgentBlock>
	 */
	private function indexById(AgentBlockCollection $blocks): array
	{
		$index = [];
		foreach ($blocks as $block)
		{
			if ($block instanceof AgentBlock)
			{
				$index[$block->id] = $block;
			}
		}

		return $index;
	}
}
