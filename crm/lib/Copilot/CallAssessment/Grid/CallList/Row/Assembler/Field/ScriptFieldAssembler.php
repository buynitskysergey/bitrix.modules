<?php

namespace Bitrix\Crm\Copilot\CallAssessment\Grid\CallList\Row\Assembler\Field;

use Bitrix\Main\Grid\Row\FieldAssembler;

final class ScriptFieldAssembler extends FieldAssembler
{
	protected function prepareRow(array $row): array
	{
		$row['columns'] ??= [];
		$title = trim($row['data']['SCRIPT_TITLE'] ?? '');
		$assessmentId = (int)($row['data']['ASSESSMENT_SETTING_ID'] ?? 0);

		$cell = $this->renderScript($title, $assessmentId);

		foreach ($this->getColumnIds() as $columnId)
		{
			$row['columns'][$columnId] = $cell;
		}

		return $row;
	}

	private function renderScript(string $title, int $assessmentId): string
	{
		if ($title === '')
		{
			return '';
		}

		if ($assessmentId <= 0)
		{
			return htmlspecialcharsbx($title);
		}

		return sprintf(
			'<a href="%s">%s</a>',
			htmlspecialcharsbx('/crm/copilot-call-assessment/details/' . $assessmentId . '/'),
			htmlspecialcharsbx($title),
		);
	}
}
