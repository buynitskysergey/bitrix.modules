<?php
/** @global CMain $APPLICATION */
/** @global string $mid */
use Bitrix\Main;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Loader;

if(!$USER->IsAdmin()) {
	return;
}

if (!Loader::includeModule('asd.iblock')) {
	return;
}

Loc::loadMessages(__FILE__);

$currentUrl = $APPLICATION->GetCurPage().'?mid='.urlencode($mid).'&amp;lang='.LANGUAGE_ID;

$list = array(
	'keep_old_sections_for_copy',
	'multiple_copy'
);

if(
	$_SERVER["REQUEST_METHOD"] == "POST"
	&& check_bitrix_sessid()
) {
	if (
		(isset ($_POST['update']) && $_POST['update'] === 'Y')
	) {
		foreach ($list as $name)
		{
			$value = '';
			if (isset($_POST[$name]))
			{
				$value = $_POST[$name];
			}
			if ($value === 'Y' || $value === 'N')
			{
				Main\Config\Option::set('asd.iblock', $name, $value, '');
			}
		}
		LocalRedirect($currentUrl);
	}
}

$options = array();
foreach ($list as $name) {
	$options[$name] = (string)Main\Config\Option::get('asd.iblock', $name);
}

$tabList = array(
	array(
		'DIV' => 'edit1',
		'TAB' => Loc::getMessage('ASD_IBLOCK_MAIN_TAB_SET'),
		'ICON' => 'ib_settings',
		'TITLE' => Loc::getMessage('ASD_IBLOCK_MAIN_TAB_TITLE_SET')
	)
);

$tabControl = new CAdminTabControl("asd_iblock_options", $tabList);

$tabControl->Begin();
?><form method="POST" action="<?=$currentUrl; ?>"><?
$tabControl->BeginNextTab();
?><tr class="heading"><td colspan="2"><?=Loc::getMessage('ASD_IBLOCK_OPERATION_SECTION_TITLE'); ?></td></tr>
<tr>
	<td style="width: 40%;"><?=Loc::getMessage('ASD_IBLOCK_OPTION_MULTIPLE_COPY');?></td>
	<td>
		<input type="hidden" name="multiple_copy" value="N">
		<input type="checkbox" name="multiple_copy" value="Y" <?=($options['multiple_copy'] == 'Y' ? ' checked' : ''); ?>>
	</td></tr>
<tr>
	<td style="width: 40%;"><?=Loc::getMessage('ASD_IBLOCK_OPTION_KEEP_OLD_SECTIONS_FOR_COPY');?></td>
	<td>
		<input type="hidden" name="keep_old_sections_for_copy" value="N">
		<input type="checkbox" name="keep_old_sections_for_copy" value="Y" <?=($options['keep_old_sections_for_copy'] == 'Y' ? ' checked' : ''); ?>>
	</td></tr><?
$tabControl->Buttons();
?><input type="submit" class="adm-btn-save" name="update" value="<? echo Loc::getMessage('ASD_IBLOCK_OPTIONS_BTN_SAVE'); ?>">
<input type="hidden" name="update" value="Y">
<?=bitrix_sessid_post();?>
</form><?
$tabControl->End();