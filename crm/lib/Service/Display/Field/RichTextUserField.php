<?php

namespace Bitrix\Crm\Service\Display\Field;

use Bitrix\Crm\Format\TextHelper;
use Bitrix\Crm\Service\Display\Options;
use Bitrix\UI\Format\BBCode\Converter;

class RichTextUserField extends BaseSimpleField
{
	public const TYPE = 'rich_text';

	protected function getFormattedValueForKanban($fieldValue, int $itemId, Options $displayOptions)
	{
		return $this->renderValues($fieldValue, $itemId, $displayOptions);
	}

	protected function getFormattedValueForGrid($fieldValue, int $itemId, Options $displayOptions)
	{
		return $this->renderValues($fieldValue, $itemId, $displayOptions);
	}

	protected function renderValues($fieldValue, int $itemId, Options $displayOptions)
	{
		if ($this->isMultiple() && is_array($fieldValue))
		{
			$result = [];
			foreach ($fieldValue as $value)
			{
				$rendered = $this->renderSingleValue($value, $itemId, $displayOptions);
				if ($rendered !== '')
				{
					$result[] = $rendered;
				}
			}

			return $result;
		}

		return $this->renderSingleValue($fieldValue, $itemId, $displayOptions);
	}

	protected function renderSingleValue($fieldValue, int $itemId, Options $displayOptions): string
	{
		if ($this->isMobileContext())
		{
			$value = parent::renderSingleValue($fieldValue, $itemId, $displayOptions);

			return TextHelper::removeParagraphs($value);
		}

		if (!is_scalar($fieldValue))
		{
			return '';
		}

		$value = htmlspecialcharsback((string)$fieldValue);
		if ($value === '')
		{
			return '';
		}

		if ($this->isExportContext())
		{
			return $value;
		}

		$html = Converter::toHtml($value);
		if (preg_match('/\[p\b/i', $value) === 1)
		{
			$html = "<div class='crm-bbcode-container --{$this->getContext()}'>{$html}</div>";
		}

		$this->setWasRenderedAsHtml(true);

		return $html;
	}
}
