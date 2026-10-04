<?php
namespace Bitrix\Main\UpdateSystem;

class UpdateServerDataParser
{
	private const WRAPER_TAG = "DATA";
	private string $text;

	public function __construct(string $text)
	{
		$this->text = $text;
	}

	public function parse(): array
	{
		$strServerOutput = $this->text;
		$arRes = [];
		$strError = '';

		\CUpdateClient::ParseServerData($strServerOutput, $arRes, $strError);

		if (!isset($arRes[self::WRAPER_TAG]) || !is_array($arRes[self::WRAPER_TAG]['#']))
		{
			return [];
		}

		$result = [];
		foreach ($arRes[self::WRAPER_TAG]['#'] as $tag => $items)
		{
			$result[$tag] = [];
			foreach ($items as $item)
			{
				$result[$tag] = array_merge($result[$tag], $item['@']);
				if (!empty($item['#']))
				{
					$result[$tag]['_VALUE'] = $item['#'];
				}
			}
		}

		return $result;
	}
}
