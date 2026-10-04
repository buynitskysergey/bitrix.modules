<?php

namespace Bitrix\Crm\Copilot\CallAssessment\Grid\CallList\Row\Assembler\Field;

use Bitrix\Main\Grid\Row\FieldAssembler;

final class AssessmentFieldAssembler extends FieldAssembler
{
	protected function prepareRow(array $row): array
	{
		$row['columns'] ??= [];
		$data = $row['data'] ?? [];
		$percent = (int)($data['ASSESSMENT'] ?? 0);
		$lowBorder = (int)($data['LOW_BORDER'] ?? 0);
		$highBorder = (int)($data['HIGH_BORDER'] ?? 0);

		$html = $this->renderBadge($percent, $lowBorder, $highBorder);

		foreach ($this->getColumnIds() as $columnId)
		{
			$row['columns'][$columnId] = $html;
		}

		return $row;
	}

	private function renderBadge(int $percent, int $lowBorder, int $highBorder): string
	{
		if ($percent <= 0)
		{
			return '';
		}

		$zone = 'default';
		if ($lowBorder > 0 && $percent < $lowBorder)
		{
			$zone = 'low';
		}
		elseif ($highBorder > 0 && $percent >= $highBorder)
		{
			$zone = 'high';
		}

		return sprintf(
			'<span class="crm-copilot-call-assessment-call-list__match-badge --%s">%d%%</span>',
			$zone,
			$percent,
		);
	}
}
