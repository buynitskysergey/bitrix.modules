<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Layout;

/**
 * Single source of truth for the frame-overlay geometry the external REST agent relies on.
 *
 * The agent declares a frame's logical membership; the server derives its geometry as the axis-aligned
 * bounding box of the member nodes' real rectangles plus a fixed padding (ALG-02). Member rectangles are
 * sized per widget type via {@see AgentBlockGeometry}, so the box matches the on-canvas render rather than a
 * uniform block size. The same primitive is used both by the forward converter
 * ({@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\AiAssistantDraftConverterService})
 * to build the persisted frame and by the post-layout checker
 * ({@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service\FramePostLayoutChecker}) to validate it,
 * so the geometry that is checked is - by construction - the exact geometry that is persisted.
 *
 * A minimum height is clamped here (MIN_HEIGHT): a frame whose members span a short vertical extent - e.g. a
 * single-row branch of a couple of side-by-side blocks - would otherwise render as a thin, visually broken
 * strip. Such a box is grown downward only, its top left at the members' padded top, up to MIN_HEIGHT. Downward
 * (rather than symmetric) growth keeps the short frame clear of a tall branching parent above it: that parent
 * sits half a BRANCH_ROW_OFFSET above its lower child ({@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Layout\TopologyLayoutEngine})
 * and, when tall, its own frame reaches down into the band, so growing this frame upward to meet it would
 * falsely intersect it; the reserved 2 x MIN_HEIGHT band leaves room below. Width is never clamped: horizontal
 * spacing (X_STEP) is the collision-sensitive axis and members already span it, so a single-member frame keeps
 * its member's real width.
 */
final class FrameGeometry
{
	/**
	 * Fixed padding grown around the member bounding box (P in ALG-02). Kept well below half the horizontal
	 * layout gap (TopologyLayoutEngine X_STEP) so a frame never reaches a neighbouring non-member block.
	 */
	public const PADDING = 40;

	/**
	 * Minimum frame height. A member bounding box shorter than this is grown downward to this value (its top is
	 * left untouched), so a shallow frame (e.g. a single-row branch) is not rendered as a thin strip. Matches the
	 * frontend rendering minimum; the layout reserves a band of 2 x MIN_HEIGHT between sibling branches
	 * ({@see \Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Layout\TopologyLayoutEngine} BRANCH_ROW_OFFSET),
	 * so a clamped frame never grows up into a tall branching parent's frame on the row above.
	 */
	public const MIN_HEIGHT = 380;

	/**
	 * ALG-02: the frame rectangle is the bounding box of the member rectangles (each member's top-left
	 * position sized by its widget type, {@see AgentBlockGeometry}) grown by PADDING on every side.
	 *
	 * @param list<array{x: int, y: int, width: int, height: int}> $memberRects member rectangles in layout space
	 * @return array{x: int, y: int, width: int, height: int}
	 */
	public static function boundingBox(array $memberRects): array
	{
		$minX = null;
		$minY = null;
		$maxX = null;
		$maxY = null;

		foreach ($memberRects as $rect)
		{
			$x = (int)$rect['x'];
			$y = (int)$rect['y'];
			$right = $x + (int)$rect['width'];
			$bottom = $y + (int)$rect['height'];

			$minX = $minX === null ? $x : min($minX, $x);
			$minY = $minY === null ? $y : min($minY, $y);
			$maxX = $maxX === null ? $right : max($maxX, $right);
			$maxY = $maxY === null ? $bottom : max($maxY, $bottom);
		}

		if ($minX === null)
		{
			// No member has a known position. Membership is guaranteed non-empty by the validator, so this
			// only guards the theoretical case of an unplaced member: a minimal padding-only box at the origin.
			return ['x' => 0, 'y' => 0, 'width' => 2 * self::PADDING, 'height' => 2 * self::PADDING];
		}

		$height = ($maxY - $minY) + 2 * self::PADDING;
		if ($height < self::MIN_HEIGHT)
		{
			// Grow downward only - the top stays at the members' padded top. A branch parent on a fractional
			// row sits half a BRANCH_ROW_OFFSET above its lower child and, when tall, its own frame reaches
			// down into the reserved band; growing this short frame upward (symmetrically) would push its top up
			// to meet that parent's frame and falsely intersect it. Downward growth keeps it clear above while
			// the reserved band leaves room below. Members' centres stay inside, so the reverse round-trip holds.
			$height = self::MIN_HEIGHT;
		}

		return [
			'x' => $minX - self::PADDING,
			'y' => $minY - self::PADDING,
			'width' => ($maxX - $minX) + 2 * self::PADDING,
			'height' => $height,
		];
	}

	/**
	 * ALG-03 membership hit-test: a node belongs to a frame iff the node's centre lies within the frame
	 * rectangle (inclusive). The centre is derived from the node's type-only base size ({@see
	 * AgentBlockGeometry::hitTestSizeForType}), the single criterion shared with the reverse projection
	 * (Phase 4) so a round-trip is idempotent regardless of the port/rule counts.
	 *
	 * @param array{x: int, y: int, width: int, height: int} $frame
	 * @param int $blockX node top-left x in layout space
	 * @param int $blockY node top-left y in layout space
	 * @param int $blockWidth node width used for the centre
	 * @param int $blockHeight node height used for the centre
	 */
	public static function contains(array $frame, int $blockX, int $blockY, int $blockWidth, int $blockHeight): bool
	{
		$centerX = $blockX + intdiv($blockWidth, 2);
		$centerY = $blockY + intdiv($blockHeight, 2);

		return $centerX >= $frame['x']
			&& $centerX <= $frame['x'] + $frame['width']
			&& $centerY >= $frame['y']
			&& $centerY <= $frame['y'] + $frame['height'];
	}

	/**
	 * Two frame rectangles overlap iff they intersect on both axes. Edge-only touching is not an overlap.
	 *
	 * @param array{x: int, y: int, width: int, height: int} $a
	 * @param array{x: int, y: int, width: int, height: int} $b
	 */
	public static function rectsOverlap(array $a, array $b): bool
	{
		return $a['x'] < $b['x'] + $b['width']
			&& $b['x'] < $a['x'] + $a['width']
			&& $a['y'] < $b['y'] + $b['height']
			&& $b['y'] < $a['y'] + $a['height'];
	}
}
