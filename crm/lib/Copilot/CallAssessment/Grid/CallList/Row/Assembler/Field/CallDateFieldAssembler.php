<?php

namespace Bitrix\Crm\Copilot\CallAssessment\Grid\CallList\Row\Assembler\Field;

use Bitrix\Main\Application;
use Bitrix\Main\Grid\Row\FieldAssembler;
use Bitrix\Main\Type\DateTime;

final class CallDateFieldAssembler extends FieldAssembler
{
	private string $format;

	public function prepareRows(array $rowList): array
	{
		$culture = Application::getInstance()->getContext()->getCulture();
		$this->format = $culture
			? $culture->getLongDateFormat() . ', ' . $culture->getShortTimeFormat()
			: 'j F, H:i'
		;

		return parent::prepareRows($rowList);
	}

	protected function prepareRow(array $row): array
	{
		$row['columns'] ??= [];
		$dt = $row['data']['ACTIVITY_START'] ?? null;

		foreach ($this->getColumnIds() as $columnId)
		{
			$row['columns'][$columnId] = $this->renderDate($dt);
		}

		return $row;
	}

	private function renderDate(?DateTime $dt): string
	{
		if ($dt === null)
		{
			return '';
		}

		return htmlspecialcharsbx(FormatDate(
			$this->format,
			$dt->toUserTime()->getTimestamp(),
		));
	}
}
