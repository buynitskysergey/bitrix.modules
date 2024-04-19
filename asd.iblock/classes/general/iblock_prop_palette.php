<?php
IncludeModuleLangFile(__FILE__);

define ('ASD_UT_PALETTE', 'SASDPalette');

class CASDiblockPropPalette {

	/**
	 * Custom property declaration.
	 * @return array
	 */
	public static function getUserTypeDescription(): array
	{
		return [
			'PROPERTY_TYPE' => 'S',
			'USER_TYPE' => ASD_UT_PALETTE,
			'DESCRIPTION' => GetMessage('ASD_UT_PALETTE_DESCR'),
			'GetPropertyFieldHtml' => [__CLASS__, 'GetPropertyFieldHtml'],
			'GetPublicEditHtml' => [__CLASS__, 'GetPublicEditHtml'],
		];
	}

	/**
	 * Returns custom field for list and edit pages.
	 * @param array $arProperty Property data.
	 * @param array $arValue Property value data.
	 * @param array $strHTMLControlName Html input data.
	 * @return string
	 */
	public static function getPropertyFieldHtml(array $arProperty, array $arValue, array $strHTMLControlName): string
	{
		$strID = preg_replace('/[^a-zA-Z0-9_]/i', 'x', $strHTMLControlName['VALUE']);
		$value = htmlspecialcharsbx(trim($arValue['VALUE']));
		CJSCore::init('color_picker');

		if (array_key_exists('MODE', $strHTMLControlName) && ($strHTMLControlName['MODE'] == 'iblock_element_admin')) {
			$strResult = '<input type="text" name="'.htmlspecialcharsbx($strHTMLControlName['VALUE']).'" id="'.$strID.'" value="'.$value.'" style="background-color: #'.($value?:'fff').'" />';
		} else {
			$strResult = '<input type="text" name="'.htmlspecialcharsbx($strHTMLControlName['VALUE']).'" id="'.$strID.'" value="'.$value.'" style="background-color: #'.($value?:'fff').'" />';
		}

		$strResult .= self::getJSCode($strID);

		return $strResult;
	}

	/**
	 * Returns custom field for list and edit pages in public area.
	 * @param array $arProperty Property data.
	 * @param array $arValue Property value data.
	 * @param array $strHTMLControlName Html input data.
	 * @return string
	 */
	public static function getPublicEditHtml(array $arProperty, array $arValue, array $strHTMLControlName): string
	{
		$strID = preg_replace('/[^a-zA-Z0-9_]/i', 'x', $strHTMLControlName["VALUE"]);
		$value = htmlspecialcharsbx(trim($arValue['VALUE']));
		CJSCore::init('color_picker');

		$strResult = '<input type="text" name="'.htmlspecialcharsbx($strHTMLControlName['VALUE']).'" id="'.$strID.'" value="'.$value.'" style="background-color: #'.($value?:'fff').'" />';
		$strResult .= self::getJSCode($strID);

		return $strResult;
	}

	/**
	 * Returns JS code for client side.
	 * @param string $strID Field ID.
	 * @return string
	 */
	private static function getJSCode(string $strID): string
	{
		return '
			<script>
				BX.ready(function()
				{
					let bindElement = BX("'.$strID.'");
					let colorPicker = new BX.ColorPicker({
						bindElement: bindElement,
						selectedColor: "#" + bindElement.value,
						onColorSelected: function(color) {
							bindElement.value = color.substring(1);
							bindElement.style.backgroundColor = color;
						},
						popupOptions: {
							offsetLeft: 20
						}
					});
					BX.bind(bindElement, "click", function() {
						colorPicker.open();
					});
				});
			</script>';
	}
}
