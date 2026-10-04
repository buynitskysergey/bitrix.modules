<?php

namespace Bitrix\Bizproc\Workflow\Template\Converter\Canvas;

use Bitrix\Bizproc\Workflow\Template\Converter\Layout;

final class CanvasNodeLayoutFactory
{
	public function createLayout(CanvasNode $node, Layout\GridPoint $point): Layout\LayoutResult
	{
		return Layout\LayoutResult::createFromNode(
			$node->findActivity(),
			$point,
			new Layout\GridFrame(
				$point->row,
				$point->column,
				$point->row + $node->findRowSpan() - 1,
				$point->column + $node->findColumnSpan() - 1
			)
		);
	}
}
