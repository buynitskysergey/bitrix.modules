<?php

namespace Bitrix\Bizproc\Workflow\Template\Converter\Canvas;

final class CanvasNodeFactory
{
	public function __construct(
		private readonly int $columnSize = 350,
		private readonly int $rowSize = 110,
	) {}

	public function createFromActivity(array $activity, ?array $span = null): CanvasNode
	{
		[$rowSpan, $columnSpan] = $span ?? $this->resolveSpanFromActivity($activity);

		return CanvasNode::createFromActivity($activity)->defineSize($rowSpan, $columnSpan);
	}

	public function resolveSpanFromActivity(array $activity): array
	{
		$description = \CBPRuntime::getRuntime()->getActivityDescription($activity['Type']);
		$nodeSettings = is_array($description['NODE_SETTINGS'] ?? null) ? $description['NODE_SETTINGS'] : [];

		$width = (float)($nodeSettings['width'] ?? $this->columnSize);
		$height = (float)($nodeSettings['height'] ?? $this->rowSize);

		$columnSpan = max(1, (int)ceil($width / $this->columnSize));
		$rowSpan = max(1, (int)ceil($height / $this->rowSize));

		return [$rowSpan, $columnSpan];
	}
}
