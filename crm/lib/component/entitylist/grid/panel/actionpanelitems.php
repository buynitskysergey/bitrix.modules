<?php

namespace Bitrix\Crm\Component\EntityList\Grid\Panel;

use Bitrix\Main\Grid\Panel\DefaultValue;

final class ActionPanelItems
{
	/**
	 * @param array $controls result of \Bitrix\Main\Grid\Panel\Panel::getControls()
	 *
	 * @return array
	 */
	public static function withoutForAllCheckbox(array $controls): array
	{
		return array_values(
			array_filter(
				$controls,
				static fn($control) => !is_array($control)
					|| ($control['ID'] ?? null) !== DefaultValue::FOR_ALL_CHECKBOX_ID
			)
		);
	}
}
