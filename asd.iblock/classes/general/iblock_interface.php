<?php
use Bitrix\Main\Loader,
	Bitrix\Main\Localization\Loc,
	Bitrix\Catalog;

Loc::loadMessages(__FILE__);

class CASDiblock {
	public static $error = '';
	public static $UF_IBLOCK = 'ASD_IBLOCK';
}

class CASDiblockInterface {

	protected static $iblockIncluded = null;
	protected static $catalogIncluded = null;

	public static function OnAdminContextMenuShow(array &$items, array &$add = array())
	{
		if (CASDiblockVersion::isIblockNewGridv18()) {
			$strCurPage = $GLOBALS['APPLICATION']->GetCurPage();

			if (
				$strCurPage=='/bitrix/admin/iblock_list_admin.php' ||
				$strCurPage=='/bitrix/admin/iblock_element_admin.php' ||
				$strCurPage=='/bitrix/admin/iblock_section_admin.php' ||
				$strCurPage=='/bitrix/admin/cat_product_admin.php' ||
				$strCurPage=='/bitrix/admin/cat_product_list.php'
			) {
				if (func_num_args() > 1)
				{
					$add[] = array(
						'TEXT' => Loc::getMessage('ASD_IBLOCK_SETT_SECT_MODE'),
						'TITLE' => Loc::getMessage('ASD_IBLOCK_SETT_SECT_MODE_TITLE'),
						'GLOBAL_ICON' => 'adm-menu-setting',
						'ONCLICK' => "location.href='".htmlspecialcharsbx($GLOBALS['APPLICATION']->GetCurPageParam('action=asd_reverse&'.bitrix_sessid_get(), array('action')))."'"
					);
				}
			}
		}
	}

	/**
	 * @param CAdminList &$list
	 * @return void
	 */
	public static function OnAdminListDisplayHandler(&$list) {
		$strCurPage = $GLOBALS['APPLICATION']->GetCurPage();
		$bElemPage = ($strCurPage=='/bitrix/admin/iblock_element_admin.php' ||
						$strCurPage=='/bitrix/admin/cat_product_admin.php'
					);
		$bSectPage = ($strCurPage=='/bitrix/admin/iblock_section_admin.php' ||
						$strCurPage=='/bitrix/admin/cat_section_admin.php'
					);
		$bMixPage = ($strCurPage=='/bitrix/admin/iblock_list_admin.php');
		$bRightPage = ($bElemPage || $bSectPage || $bMixPage);

		if (!$bRightPage) {
			return;
		}

		if (self::$iblockIncluded === null) {
			self::$iblockIncluded = Loader::includeModule('iblock');
		}
		if (self::$catalogIncluded === null) {
			self::$catalogIncluded = Loader::includeModule('catalog');
		}
		if (!self::$iblockIncluded) {
			return;
		}

		if (!empty($list->arActions)) {
			CJSCore::Init(array('asd_iblock'));
			$strSomeScripts  = '<script type="text/javascript">sListTable = \''.$list->table_id.'\';</script>';
			$list->arActions['asd_checkbox_manager'] = array('type' => 'html', 'value' => $strSomeScripts);
		}

		if (!$list->bEditMode && !isset($_REQUEST['mode'])) {
			if (CASDiblockVersion::isIblockNewGridv18()) {
				CJSCore::Init(array('asd_element_ui_list'));
				$jsParams = array(
					'resultDiv' => $list->table_id.'_table'
				);
				$list->sPrologContent .= '<script type="text/javascript">
var asdElementList'.$list->table_id.' = new AsdIblockElementUiList('.CUtil::PhpToJSObject($jsParams, false, true, true).');
</script>';
			} else {
				CJSCore::Init(array('asd_element_list'));
				$jsParams = array(
					'resultDiv' => $list->table_id.'_result_div'
				);
				$list->sPrologContent .= '<script type="text/javascript">
var asdElementList'.$list->table_id.' = new AsdIblockElementList('.CUtil::PhpToJSObject($jsParams, false, true, true).');
</script>';
			}
		}

		if (!CASDiblockVersion::isIblockNewGridv18()) {
			if ($bMixPage ||
				$strCurPage=='/bitrix/admin/iblock_element_admin.php' ||
				$strCurPage=='/bitrix/admin/iblock_section_admin.php' ||
				$strCurPage=='/bitrix/admin/cat_product_admin.php' ||
				$strCurPage=='/bitrix/admin/cat_product_list.php'
			) {
				$list->context->additional_items[] = array(
					'TEXT' => Loc::getMessage('ASD_IBLOCK_SETT_SECT_MODE'),
					'TITLE' => Loc::getMessage('ASD_IBLOCK_SETT_SECT_MODE_TITLE'),
					'GLOBAL_ICON' => 'adm-menu-setting',
					'ONCLICK' => "location.href='".htmlspecialcharsbx($GLOBALS['APPLICATION']->GetCurPageParam('action=asd_reverse&'.bitrix_sessid_get(), array('action')))."'"
				);
			}
		}

		if (!empty(CASDiblock::$error)) {
			$list->AddGroupError(CASDiblock::$error);
		}

		$IBLOCK_ID = intval($_REQUEST['IBLOCK_ID']);
		$find_section = intval($_REQUEST['find_section_section']);
		if ($find_section < 0)
			$find_section = 0;

		$boolSectionCopy = CASDIblockRights::IsSectionSectionCreate($IBLOCK_ID, $find_section);
		$boolElementCopy = CASDIblockRights::IsSectionElementCreate($IBLOCK_ID, $find_section);

		$catalogType = null;
		$transferCopy = array();

		$copyMessageId = 'ASD_ACTION_POPUP_COPY';
		$copySimpleMessageId = 'ASD_ACTION_POPUP_COPY_AS_SIMPLE_PRODUCT';
		$copyContextMessageId = 'ASD_ACTION_COPY';
		$moveContextMessageId = 'ASD_ACTION_MOVE';

		if (self::$catalogIncluded) {
			$catalog = CCatalogSku::GetInfoByIBlock($IBLOCK_ID);
			if (!empty($catalog)) {
				$catalogType = $catalog['CATALOG_TYPE'];
			}
			unset($catalog);
			if ($catalogType == CCatalogSku::TYPE_FULL
				|| $catalogType == CCatalogSku::TYPE_PRODUCT
				|| $catalogType == CCatalogSku::TYPE_CATALOG
			) {
				if (CASDModuleVersion::checkMinVersion('catalog', '16.0.4')) {
					$elementIds = array();
					if (!empty ($list->aRows)) {
						if ($bElemPage && $boolElementCopy) {
							foreach ($list->aRows as $row) {
								$elementIds[] = (int)$row->id;
							}
							unset ($row);
						} elseif ($bMixPage && $boolElementCopy) {
							foreach ($list->aRows as $row) {
								if (strncmp($row->id, 'E', 1) == 0) {
									$elementIds[] = (int)substr($row->id, 1);
								}
							}
							unset ($row);
						}
					}
					if (!empty($elementIds)) {
						sort($elementIds);
						foreach (array_chunk($elementIds, 500) as $pageIds) {
							$iterator = Catalog\ProductTable::getList(array(
								'select' => array('ID', 'TYPE'),
								'filter' => array('@ID' => $pageIds)
							));
							while ($row = $iterator->fetch()) {
								if ($row['TYPE'] == Catalog\ProductTable::TYPE_SET
									|| $row['TYPE'] == Catalog\ProductTable::TYPE_SKU
									|| $row['TYPE'] == Catalog\ProductTable::TYPE_EMPTY_SKU
								) {
									if ($bElemPage) {
										$transferCopy[(int)$row['ID']] = true;
									} else {
										$transferCopy['E'.$row['ID']] = true;
									}
								}
							}
							unset($row, $iterator);
						}
						unset($pageIds);
					}
					unset($elementIds);
				} else {
					$copyMessageId = $copySimpleMessageId;
					$copyContextMessageId = 'ASD_ACTION_COPY_AS_SIMPLE_PRODUCT';
					$moveContextMessageId = 'ASD_ACTION_MOVE_AS_SIMPLE_PRODUCT';
				}
			}
			if (!empty($transferCopy)) {
				$copyContextMessageId = 'ASD_ACTION_COPY_AS_SIMPLE_PRODUCT';
				$moveContextMessageId = 'ASD_ACTION_MOVE_AS_SIMPLE_PRODUCT';
			}
		}

		if ($bElemPage) {
			if ($boolElementCopy && !empty($list->aRows)) {
				foreach ($list->aRows as $id => $v) {
					$actionMessageId = (isset($transferCopy[$v->id]) ? $copySimpleMessageId : $copyMessageId);
					$arnewActions = array();
					foreach ($v->aActions as $i => $act) {
						$arnewActions[] = $act;
						if ($act['ICON'] == 'copy') {
							$arnewActions[] = array('ICON' => 'copy',
												'TEXT' => Loc::getMessage($actionMessageId),
												'ACTION' => $list->ActionDoGroup($v->id, 'asd_copy_in_list',
															'&type='.urlencode($_REQUEST['type']).'&lang='.LANGUAGE_ID.'&IBLOCK_ID='.$IBLOCK_ID.'&find_section_section='.$find_section),
												);
						}
					}
					$v->AddActions($arnewActions);
				}
				unset($arnewActions);
				unset($actionMessageId);
				unset($id);
				unset($v);
			}
		} elseif ($bSectPage) {
			if ($boolSectionCopy) {
				foreach ($list->aRows as $id => $v) {
					$arnewActions = array();
					foreach ($v->aActions as $i => $act) {
						$arnewActions[] = $act;
						if ($act['ICON'] == 'edit') {
							$arnewActions[] = array('ICON' => 'copy',
													'TEXT' => Loc::getMessage('ASD_ACTION_POPUP_COPY'),
													'ACTION' => $list->ActionDoGroup($v->id, 'asd_copy_in_list',
																'&type='.urlencode($_REQUEST['type']).'&lang='.LANGUAGE_ID.'&IBLOCK_ID='.$IBLOCK_ID.'&find_section_section='.$find_section),
													);
						}
					}
					$v->AddActions($arnewActions);
				}
			}
		} else {
			foreach ($list->aRows as $id => $v) {
				$strPrefix = substr($v->id, 0, 1);
				if ($strPrefix == 'E') {
					if ($boolElementCopy) {
						$actionMessageId = (isset($transferCopy[$v->id]) ? $copySimpleMessageId : $copyMessageId);
						$arnewActions = array();
						foreach ($v->aActions as $i => $act) {
							$arnewActions[] = $act;
							if ($act['ICON'] == 'copy') {
								$arnewActions[] = array('ICON' => 'copy',
													'TEXT' => Loc::getMessage($actionMessageId),
													'ACTION' => $list->ActionDoGroup($v->id, 'asd_copy_in_list',
																'&type='.urlencode($_REQUEST['type']).'&IBLOCK_ID='.$IBLOCK_ID.'&find_section_section='.$find_section),
													);
							}
						}
						$v->AddActions($arnewActions);
					}
				}
				elseif ($strPrefix == 'S')
				{
					if ($boolSectionCopy) {
						$arnewActions = array();
						foreach ($v->aActions as $i => $act) {
							$arnewActions[] = $act;
							if ($act['ICON'] == 'edit') {
								$arnewActions[] = array('ICON' => 'copy',
													'TEXT' => Loc::getMessage('ASD_ACTION_POPUP_COPY'),
													'ACTION' => $list->ActionDoGroup($v->id, 'asd_copy_in_list',
																'&type='.urlencode($_REQUEST['type']).'&lang='.LANGUAGE_ID.'&IBLOCK_ID='.$IBLOCK_ID.'&find_section_section='.$find_section),
													);
							}
						}
						$v->AddActions($arnewActions);
					}
				}
			}
		}

		$arIBtypes = array();
		$rsIBtype = CIBlockType::GetList();
		while($arIBtype = $rsIBtype->Fetch()) {
			if ($arIBTypeLang = CIBlockType::GetByIDLang($arIBtype['ID'], LANGUAGE_ID)) {
				$arIBtypes[$arIBTypeLang['IBLOCK_TYPE_ID']] = $arIBTypeLang['NAME'];
			}
		}

		$arIBblocks = array();
		$rsIB = CIBlock::GetList(array("IBLOCK_TYPE" => "ASC", "NAME" => "ASC"), array("MIN_PERMISSION" => "W"));
		while ($arIB = $rsIB->GetNext(true, false)) {
			if (!isset($arIBblocks[$arIB['IBLOCK_TYPE_ID']])) {
				$arIBblocks[$arIB['IBLOCK_TYPE_ID']] = array('NAME' => $arIBtypes[$arIB['IBLOCK_TYPE_ID']], 'ITEMS' => array());
			}
			$arIBblocks[$arIB['IBLOCK_TYPE_ID']]['ITEMS'][] = array('ID' => $arIB['ID'], 'NAME' => $arIB['NAME']);
		}

		if (CASDiblockVersion::isIblockNewGridv18()) {
			if (CASDIblockRights::IsSectionElementEdit($IBLOCK_ID, $find_section) && ($bElemPage || $bMixPage)) {
				$list->arActions['asd_remove'] = Loc::getMessage('ASD_ACTION_REMOVE');
			}

			$multiControl = CASDModuleVersion::checkMinVersion('main', '18.1.8');

			$iblockListCopy = array();
			$iblockListMove = array();
			if (!empty($arIBblocks)) {
				foreach ($arIBblocks as $arType) {
					foreach ($arType['ITEMS'] as $arIB) {
						$row = array(
							'NAME' => '['.$arIB['ID'].'] '.$arIB['NAME'],
							'VALUE' => $arIB['ID'],

						);
						if ($multiControl) {
							$row['ONCHANGE'] = array(
								array(
									'ACTION' => \Bitrix\Main\Grid\Panel\Actions::CALLBACK,
									'DATA' => array(
										array(
											'JS' => "ASDSetCurrentIblock('asd_sect_iblock_id_copy', ".$arIB['ID'].");"
										)
									)
								)
							);
						}
						$iblockListCopy[] = $row;
						if ($arIB['ID'] != $IBLOCK_ID) {
							$row = array(
								'NAME' => '['.$arIB['ID'].'] '.$arIB['NAME'],
								'VALUE' => $arIB['ID']
							);
							if ($multiControl) {
								$row['ONCHANGE'] = array(
									array(
										'ACTION' => \Bitrix\Main\Grid\Panel\Actions::CALLBACK,
										'DATA' => array(
											array(
												'JS' => "ASDSetCurrentIblock('asd_sect_iblock_id_move', ".$arIB['ID'].");"
											)
										)
									)
								);
							}
							$iblockListMove[] = $row;
						}
					}
					unset($arIB);
				}
				unset($arType);
			}

			if ($multiControl) {
				$enableMultiSelect = !$bSectPage && (string)\Bitrix\Main\Config\Option::get('asd.iblock', 'enable_section_multiselect') === 'Y';

				$snippet = new \Bitrix\Main\Grid\Panel\Snippet();

				if (!empty($iblockListCopy)) {
					$list->arActions['asd_copy'] = array(
						'name' => Loc::getMessage($copyContextMessageId),
						'type' => 'multicontrol',
						'action' => array(
							array(
								'ACTION' => \Bitrix\Main\Grid\Panel\Actions::CREATE,
								'DATA' => array(
									[
										'TYPE' => \Bitrix\Main\Grid\Panel\Types::BUTTON,
										'ID' => 'asd_sect_id_btn_copy',
										'TEXT' => Loc::getMessage('ASD_SELECT_BUTTON_ACTION'),
										'TITLE' => Loc::getMessage('ASD_SELECT_BUTTON_DESCR'),
										'ONCHANGE' => [
											[
												'ACTION' => \Bitrix\Main\Grid\Panel\Actions::CALLBACK,
												'DATA' => [
													[
														'JS' => "ASDShowSectionWindow({
														languageId: '".LANGUAGE_ID."',
														multiSelect: ".($enableMultiSelect ? 'true' : 'false').",
														destId: 'asd_ib_dest_copy',
														destSection: 'asd_sect_id_copy',
														destDescrIblock: 'asd_sect_id_copy_descr_iblock',
														destDescrSection: 'asd_sect_id_copy_descr_sect'
													});"
													]
												]
											]
										]
									],
									[
										'TYPE' => \Bitrix\Main\Grid\Panel\Types::HIDDEN,
										'ID' => 'asd_ib_dest_copy',
										'NAME' => 'asd_ib_dest',
										'VALUE' => '0'
									],
									[
										'TYPE' => \Bitrix\Main\Grid\Panel\Types::CUSTOM,
										'ID' => 'asd_sect_id_copy_descr_border',
										'VALUE' => '<div id="asd_sect_id_copy_descr" style="max-width: 250px;">'
											.'<div id="asd_sect_id_copy_descr_iblock"></div>'
											.'<div id="asd_sect_id_copy_descr_sect"></div>'
											.'</div>'
									],
									[
										'TYPE' => \Bitrix\Main\Grid\Panel\Types::HIDDEN,
										'ID' => 'asd_sect_id_copy',
										'NAME' => 'asd_sect_dest',
										'VALUE' => '0'
									],
									$snippet->getApplyButton(array(
										'ONCHANGE' => array(
											array(
												'ACTION' => \Bitrix\Main\Grid\Panel\Actions::CALLBACK,
												'DATA' => array(
													array(
														'JS' => "BX.adminUiList.SendSelected('{$list->table_id}');"
													)
												)
											)
										)
									))
								)
							),
							array(
								'ACTION' => \Bitrix\Main\Grid\Panel\Actions::REMOVE,
								'DATA' => array(
									array('ID' => 'asd_sect_id_move_descr_border'),
									array('ID' => 'asd_ib_dest_move'),
									array('ID' => 'asd_sect_id_move'),
									array('ID' => 'asd_sect_iblock_id_move'),
									array('ID' => 'asd_sect_name_move'),
									array('ID' => 'asd_sect_id_btn_move')
								)
							)
						)
					);
				}
				if (!empty($iblockListMove)) {
					$list->arActions['asd_move'] = array(
						'name' => Loc::getMessage($moveContextMessageId),
						'type' => 'multicontrol',
						'action' => array(
							array(
								'ACTION' => \Bitrix\Main\Grid\Panel\Actions::CREATE,
								'DATA' => array(
									[
										'TYPE' => \Bitrix\Main\Grid\Panel\Types::BUTTON,
										'ID' => 'asd_sect_id_btn_move',
										'TEXT' => Loc::getMessage('ASD_SELECT_BUTTON_ACTION'),
										'TITLE' => Loc::getMessage('ASD_SELECT_BUTTON_DESCR'),
										'ONCHANGE' => [
											[
												'ACTION' => \Bitrix\Main\Grid\Panel\Actions::CALLBACK,
												'DATA' => [
													[
														'JS' => "ASDShowSectionWindow({
														languageId: '".LANGUAGE_ID."',
														multiSelect: ".($enableMultiSelect ? 'true' : 'false').",
														destId: 'asd_ib_dest_move',
														destSection: 'asd_sect_id_move',
														destDescrIblock: 'asd_sect_id_move_descr_iblock',
														destDescrSection: 'asd_sect_id_move_descr_sect'
													});"
													]
												]
											]
										]
									],
									[
										'TYPE' => \Bitrix\Main\Grid\Panel\Types::HIDDEN,
										'ID' => 'asd_ib_dest_move',
										'NAME' => 'asd_ib_dest',
										'VALUE' => '0'
									],
									[
										'TYPE' => \Bitrix\Main\Grid\Panel\Types::CUSTOM,
										'ID' => 'asd_sect_id_move_descr_border',
										'VALUE' => '<div id="asd_sect_id_move_descr" style="max-width: 250px;">'
											.'<div id="asd_sect_id_move_descr_iblock"></div>'
											.'<div id="asd_sect_id_move_descr_sect"></div>'
											.'</div>'
									],
									[
										'TYPE' => \Bitrix\Main\Grid\Panel\Types::HIDDEN,
										'ID' => 'asd_sect_id_move',
										'NAME' => 'asd_sect_dest',
										'VALUE' => '0'
									],
									$snippet->getApplyButton(array(
										'ONCHANGE' => array(
											array(
												'ACTION' => \Bitrix\Main\Grid\Panel\Actions::CALLBACK,
												'DATA' => array(
													array(
														'JS' => "BX.adminUiList.SendSelected('{$list->table_id}');"
													)
												)
											)
										)
									))
								)
							),
							array(
								'ACTION' => \Bitrix\Main\Grid\Panel\Actions::REMOVE,
								'DATA' => array(
									array('ID' => 'asd_sect_id_copy_descr_border'),
									array('ID' => 'asd_ib_dest_copy'),
									array('ID' => 'asd_sect_id_copy'),
									array('ID' => 'asd_sect_iblock_id_copy'),
									array('ID' => 'asd_sect_name_copy'),
									array('ID' => 'asd_sect_id_btn_copy')
								)
							)
						)
					);
				}
			} else {
				if (!empty($iblockListCopy)) {
					$list->arActions['asd_copy'] = array(
						'lable' => Loc::getMessage($copyContextMessageId),
						'label' => Loc::getMessage($copyContextMessageId),
						'type' => 'select',
						'name' => 'asd_ib_dest',
						'items' => $iblockListCopy
					);
				}
				if (!empty($iblockListMove)) {
					$list->arActions['asd_move'] = array(
						'lable' => Loc::getMessage($moveContextMessageId),
						'label' => Loc::getMessage($moveContextMessageId),
						'type' => 'select',
						'name' => 'asd_ib_dest',
						'items' => $iblockListMove
					);
				}
			}
			unset($iblockListMove);
			unset($iblockListCopy);
		} else {
			$boolAccess = false;
			$strIBlocksCp = '<div id="asd_ib_dest_cont" style="display:none; "><select class="typeselect" name="asd_ib_dest" id="asd_ib_dest">';
			foreach ($arIBblocks as &$arType) {
				$strIBlocksCpGr = '';
				foreach ($arType['ITEMS'] as &$arIB) {
					if (CASDIblockRights::IsIBlockDisplay($arIB['ID'])) {
						$boolAccess = true;
						$strIBlocksCpGr .= '<option value="' . $arIB['ID'] . '">' . $arIB['NAME'] . '</option>';
					}
				}
				if (isset($arIB)) {
					unset($arIB);
				}
				if ($strIBlocksCpGr != '') {
					$strIBlocksCp .= '<optgroup label="' . $arType['NAME'] . '">';
					$strIBlocksCp .= $strIBlocksCpGr;
					$strIBlocksCp .= '</optgroup>';
				}
			}
			if (isset($arType)) {
				unset($arType);
			}
			$strIBlocksCp .= '</select></div>';

			$strSectionSelect = '<div id="asd_ib_dest_sect" class="asd-sect-cont" style="display:none;" title="'.htmlspecialcharsbx(Loc::getMessage('ASD_SELECT_SECTION_DESCR')).'">'.
							htmlspecialcharsex(Loc::getMessage('ASD_SELECT_SECTION')).
							'&nbsp;<input class="asd-sect-input" type="text" id="asd_sect_id" value="" name="asd_sect_dest" size="4" title="">'.
							'<span id="sp_asd_sect_id" class="asd-sect-descr"></span>'.
							'<input type="button" onclick="ASDSelIBShow(\''.LANGUAGE_ID.'\');" value="'.
							htmlspecialcharsbx(Loc::getMessage('ASD_SELECT_BUTTON')).
							'" title="'.htmlspecialcharsbx(Loc::getMessage('ASD_SELECT_BUTTON_DESCR')).'"></div>';

			if (CASDIblockRights::IsSectionElementEdit($IBLOCK_ID, $find_section) && ($bElemPage || $bMixPage)) {
				$list->arActions['asd_remove'] = Loc::getMessage('ASD_ACTION_REMOVE');
			}

			if ($boolAccess) {
				$list->arActions['asd_copy'] = Loc::getMessage($copyContextMessageId);
				if ($bElemPage || $bMixPage) {
					$list->arActions['asd_move'] = Loc::getMessage($moveContextMessageId);
				}
				$list->arActions['asd_copy_move'] = array('type' => 'html', 'value' => $strIBlocksCp);
				$list->arActions['asd_copy_move_sect'] = array('type' => 'html', 'value' => $strSectionSelect);

				$list->arActionsParams['select_onchange'] .= "ASDSelIBChange(this.value);";
			}
		}
	}

	/**
	 * @param CAdminSubList &$list
	 * @return void
	 */
	public static function OnAdminSubListDisplayHandler(&$list) {
		$strCurPage = $GLOBALS['APPLICATION']->GetCurPage();
		$bElemPage = ($strCurPage=='/bitrix/admin/iblock_element_edit.php' ||
			$strCurPage=='/bitrix/admin/cat_product_edit.php'
		);

		if ($bElemPage && !$list->bEditMode && !isset($_REQUEST['mode'])) {
			CJSCore::Init(array('asd_element_list'));
			$jsParams = array(
				'resultDiv' => $list->table_id.'_result_div'
			);
			$list->sPrologContent .= '<script type="text/javascript">
var asdElementList'.$list->table_id.' = new AsdIblockElementList('.CUtil::PhpToJSObject($jsParams, false, true, true).');
</script>';
			}
	}

	public static function OnAdminContextMenuShowHandler(&$items) {
		if ($GLOBALS['APPLICATION']->GetCurPage()=='/bitrix/admin/iblock_edit.php' && $_REQUEST['ID']>0) {
			CJSCore::Init(array('asd_iblock'));
			$BID = intval($_REQUEST['ID']);
			$importAction = "javascript:(new BX.CDialog({
							width: 310,
							height: 110,
							resizable: false,
							title: '".Loc::getMessage('ASD_ACTION_IMPORT_FORM')."',
							content: '<form action=\"".CUtil::JSEscape($GLOBALS['APPLICATION']->GetCurPageParam('', array('action')))."\" method=\"post\" enctype=\"multipart/form-data\">"
										.bitrix_sessid_post()
										."<input type=\"hidden\" name=\"action\" value=\"asd_prop_import\" />"
										."<input type=\"hidden\" name=\"ID\" value=\"".$BID."\" />"
										."<input type=\"hidden\" name=\"type\" value=\"".htmlspecialcharsbx($_REQUEST['type'])."\" />"
										."<input type=\"file\" name=\"xml_file\" /><br/><br/>"
										."<center><input type=\"submit\" value=\"".Loc::getMessage('ASD_ACTION_IMPORT_SUBMIT')."\" /></center>"
									."</form>'
						})).Show()";
			$exportAction = "javascript:(new BX.CDialog({
							width: 310,
							height: 200,
							resizable: false,
							title: '".Loc::getMessage('ASD_ACTION_EXPORT_FORM')."',
							buttons: [BX.CAdminDialog.btnSave, BX.CAdminDialog.btnCancel],
							content: '<form action=\"".CUtil::JSEscape($GLOBALS['APPLICATION']->GetCurPageParam('', array('action')))."\" method=\"post\" enctype=\"multipart/form-data\">"
										.bitrix_sessid_post()
										."<input type=\"hidden\" name=\"action\" value=\"asd_prop_export\" />"
										."<input type=\"hidden\" name=\"ID\" value=\"".$BID."\" />";

			$exportAction .= '<input type="checkbox" name="forms" id="forms" value="Y" />'.
							'<label for="forms">'.Loc::getMessage('ASD_ACTION_EXPORT_FORMS').'</label><br/><br/>';
			$exportAction .= '<input type="checkbox" id="asd_export_prop_all" checked="checked" />'.
							'<label for="asd_export_prop_all"><i>'.Loc::getMessage('ASD_ACTION_EXPORT_ALL').'</i></label><br/>';
			$rsProp = CIBlockProperty::GetList(array(), array('IBLOCK_ID' => $BID));
			while ($arProp = $rsProp->GetNext()) {
				$exportAction .= '<input type="checkbox" class="asd_export_prop" name="p['.$arProp['ID'].']" id="p'.$arProp['ID'].'" value="Y" checked="checked" />'.
								'<label for="p'.$arProp['ID'].'" title="'.$arProp['CODE'].'">'.$arProp['NAME'].'</label><br/>';
			}

			$exportAction .=	"</form>'
							})).Show()";
			$items[] = array(
				'TEXT' => Loc::getMessage('ASD_ACTION_EXPORT_IMPORT'),
				'TITLE' => Loc::getMessage('ASD_ACTION_EXPORT_IMPORT_TITLE'),
				'LINK' => '#',
				'ICON' => 'btn_settings',
				'MENU' => array(
					array(
						'TEXT' => Loc::getMessage('ASD_ACTION_EXPORT_PROP'),
						'ACTION' => version_compare(SM_VERSION, '11.5.5')>=0 ?  $exportAction : htmlspecialcharsbx($exportAction),
					),
					array(
						'TEXT' => Loc::getMessage('ASD_ACTION_IMPORT_PROP'),
						'ACTION' => version_compare(SM_VERSION, '11.5.5')>=0 ?  $importAction : htmlspecialcharsbx($importAction),
					),
				),
			);
		}
		if (($GLOBALS['APPLICATION']->GetCurPage()=='/bitrix/admin/iblock_element_edit.php' ||
			$GLOBALS['APPLICATION']->GetCurPage()=='/bitrix/admin/cat_product_edit.php') && $_REQUEST['ID']>0 &&
			(!isset($_REQUEST['action']) && $_REQUEST['action']!='copy')
		) {
			if ($arElement = CIBlockElement::GetByID($_REQUEST['ID'])->GetNext()) {
				if ((string)$arElement['CANONICAL_PAGE_URL'] !== '') {
					$items[] = array('ICON' => 'asd_iblock_show_element',
									'TEXT' => Loc::getMessage('ASD_ACTION_VIEW_DETAIL'),
									'LINK' => str_replace('%2F', '/', $arElement['CANONICAL_PAGE_URL']),
									);
				}
				else if ((string)$arElement['DETAIL_PAGE_URL'] !== '') {
					$items[] = array('ICON' => 'asd_iblock_show_element',
									'TEXT' => Loc::getMessage('ASD_ACTION_VIEW_DETAIL'),
									'LINK' => str_replace('%2F', '/', $arElement['DETAIL_PAGE_URL']),
									);
				}
			}
		}
	}

	public static function OnAdminTabControlBeginHandler(&$form) {
		static $bPublicLinkShow = false;
		if (!$bPublicLinkShow && array_key_exists('ID', $_REQUEST) && intval($_REQUEST['ID'])>0 && $_REQUEST['bxpublic']=='Y' &&
			($GLOBALS['APPLICATION']->GetCurPage()=='/bitrix/admin/iblock_element_edit.php' ||
			$GLOBALS['APPLICATION']->GetCurPage()=='/bitrix/admin/cat_product_edit.php')
			&& !CASDiblockVersion::checkMinVersion('15.5.8')
		) {
			$bPublicLinkShow = true;
			?>
			<div style="float: right">
				<a style="text-decoration: none;" href="/bitrix/admin/iblock_element_edit.php?ID=<?= intval($_REQUEST['ID'])?>&amp;<?
						?>type=<?= htmlspecialcharsback($_REQUEST['type'])?>&amp;<?
						?>lang=<?= LANGUAGE_ID?>&amp;<?
						?>IBLOCK_ID=<?= $_REQUEST['IBLOCK_ID']?>"><?= Loc::getMessage('ASD_IBLOCK_IN_ADMIN')?></a>
			</div>
			<?
		} elseif ($GLOBALS['APPLICATION']->GetCurPage()=='/bitrix/admin/iblock_edit.php' &&
			array_key_exists('ID', $_REQUEST) && intval($_REQUEST['ID'])>0
		) {
			global $USER_FIELD_MANAGER, $APPLICATION;
			$ID = intval($_REQUEST['ID']);
			$PROPERTY_ID = CASDiblock::$UF_IBLOCK;
			$bVarsFromForm = $_SERVER['REQUEST_METHOD']=='POST';
			if ($USER_FIELD_MANAGER->GetRights($PROPERTY_ID) >= 'W') {
				ob_start();
				if(method_exists($USER_FIELD_MANAGER, 'showscript')) {
					echo $USER_FIELD_MANAGER->ShowScript();
				}
				?>
				<tr>
					<td colspan="2" align="left">
						<a href="/bitrix/admin/userfield_edit.php?lang=<?= LANGUAGE_ID?><?
						?>&amp;ENTITY_ID=<?= urlencode($PROPERTY_ID)?>&amp;back_url=<?= urlencode($APPLICATION->GetCurPageParam().'&tabControl_active_tab=user_fields_tab')?><?
						?>"><?= Loc::getMessage('ASD_IBLOCK_ADD_UF')?></a>
					</td>
				</tr>
				<?
				$arUserFields = $USER_FIELD_MANAGER->GetUserFields($PROPERTY_ID, $ID, LANGUAGE_ID);
				foreach($arUserFields as $FIELD_NAME => $arUserField) {

					$arUserField['VALUE_ID'] = $ID;
					if (isset($_REQUEST['def_'.$FIELD_NAME])) {
						$arUserField['SETTINGS']['DEFAULT_VALUE'] = $_REQUEST['def_'.$FIELD_NAME];
					}

					echo $USER_FIELD_MANAGER->GetEditFormHTML($bVarsFromForm, $GLOBALS[$FIELD_NAME], $arUserField);
				}
				$strContent = ob_get_contents();
				ob_end_clean();

				$arTab = $GLOBALS['USER_FIELD_MANAGER']->EditFormTab($PROPERTY_ID);
				$arTab['CONTENT'] = $strContent;
				$form->tabs[] = $arTab;
			}
		}
	}
}