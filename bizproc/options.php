<?php

/** @global CMain $APPLICATION */

use Bitrix\Bizproc\Internal\Service\Storage\StorageLimitsService;

$module_id = "bizproc";
$bizprocPerms = $APPLICATION::GetGroupRight($module_id);
if ($bizprocPerms >= "R") :

	CModule::IncludeModule("bizproc");

	IncludeModuleLangFile($_SERVER["DOCUMENT_ROOT"] . "/bitrix/modules/main/options.php");
	IncludeModuleLangFile(__FILE__);

	$dbSites = CSite::GetList("", "", ["ACTIVE" => "Y"]);
	$arSites = [];
	$aSubTabs = [];
	while ($site = $dbSites->Fetch())
	{
		$site["ID"] = htmlspecialcharsbx($site["ID"]);
		$site["NAME"] = htmlspecialcharsbx($site["NAME"]);
		$arSites[] = $site;

		$aSubTabs[] = ["DIV" => "opt_site_" . $site["ID"], "TAB" => "(" . $site["ID"] . ") " . $site["NAME"], 'TITLE' => ''];
	}
	$subTabControl = new CAdminViewTabControl("subTabControl", $aSubTabs);

	if (
		$_SERVER['REQUEST_METHOD'] === "GET"
		&& !empty($_REQUEST['RestoreDefaults'])
		&& $bizprocPerms === "W"
		&& check_bitrix_sessid()
	)
	{
		COption::RemoveOption("bizproc");
	}

	$defaultValue = \Bitrix\Main\ModuleManager::isModuleInstalled('bitrix24') ? 'Y' : 'N';
	$arAllOptions = [
		["log_cleanup_days", GetMessage("BIZPROC_LOG_CLEANUP_DAYS"), "90", ["text", 3]],
		["search_cleanup_days", GetMessage("BIZPROC_SEARCH_CLEANUP_DAYS"), "180", ["text", 3]],
		['storage_item_data_limit', GetMessage('BIZPROC_OPT_STORAGE_ITEM_DATA_LIMIT'), '1', ['text', 3]],
		['storage_disk_block_buffer', GetMessage('BIZPROC_OPT_STORAGE_DISK_BLOCK_BUFFER'), '1024', ['text', 5]],
		['storage_disk_warning_buffer', GetMessage('BIZPROC_OPT_STORAGE_DISK_WARNING_BUFFER'), '2048', ['text', 5]],
		["log_skip_types", GetMessage("BIZPROC_LOG_SKIP_TYPES"), "1,2", ["checkboxlist", [
			1 => GetMessage("BIZPROC_LOG_SKIP_TYPES_1_1"),
			2 => GetMessage("BIZPROC_LOG_SKIP_TYPES_2_1"),
		]]],
		["automation_no_forced_tracking", GetMessage("BIZPROC_AUTOMATION_NO_FORCED_TRACKING"), "N", ["checkbox"]],
		["limit_simultaneous_processes", GetMessage("BIZPROC_LIMIT_SIMULTANEOUS_PROCESSES"), "", ["text", 3]],
		["employee_compatible_mode", GetMessage("BIZPROC_EMPLOYEE_COMPATIBLE_MODE"), "N", ["checkbox"]],
		["limit_while_iterations", GetMessage("BIZPROC_LIMIT_WHILE_ITERATIONS"), "1000", ["text", 5]],
		['enable_getdocument_select', GetMessage('BIZPROC_OPT_ENABLE_GETDOCUMENT_SELECT'), $defaultValue, ['checkbox']],
	];

	$strWarning = "";
	if (
		$_SERVER['REQUEST_METHOD'] === "POST"
		&& !empty($_REQUEST['Update'])
		&& $bizprocPerms === "W"
		&& check_bitrix_sessid()
	)
	{
		COption::SetOptionString("bizproc", "log_cleanup_days", ($_REQUEST['log_cleanup_days'] ?? 0));
		if (($_REQUEST['log_cleanup_days'] ?? 0) > 0)
		{
			CAgent::AddAgent("CBPTrackingService::ClearOldAgent();", "bizproc", "N", 86400);
		}
		else
		{
			CAgent::RemoveAgent("CBPTrackingService::ClearOldAgent();", "bizproc");
		}

		COption::SetOptionString("bizproc", "search_cleanup_days", ($_REQUEST['search_cleanup_days'] ?? 0));
		if (($_REQUEST['search_cleanup_days'] ?? 0) > 0)
		{
			CAgent::AddAgent(\Bitrix\Bizproc\Worker\Workflow\ClearFilterAgent::getName(), "bizproc", "N", 86400);
			CAgent::AddAgent(\Bitrix\Bizproc\Worker\Task\ClearSearchContentAgent::getName(), "bizproc", "N", 86400);
		}
		else
		{
			CAgent::RemoveAgent(\Bitrix\Bizproc\Worker\Workflow\ClearFilterAgent::getName(), "bizproc");
			CAgent::RemoveAgent(\Bitrix\Bizproc\Worker\Task\ClearSearchContentAgent::getName(), "bizproc");
		}

		COption::SetOptionString("bizproc", "employee_compatible_mode", ($_REQUEST['employee_compatible_mode'] ?? 'N') === "Y" ? "Y" : "N");
		COption::SetOptionString("bizproc", "limit_simultaneous_processes", ($_REQUEST['limit_simultaneous_processes'] ?? 0) ? $_REQUEST['limit_simultaneous_processes'] : 0);
		COption::SetOptionString("bizproc", "limit_while_iterations", ($_REQUEST['limit_while_iterations'] ?? 1000));
		COption::SetOptionString("bizproc", "log_skip_types", ($_REQUEST['log_skip_types'] ?? '') ? implode(',', (array)$_REQUEST['log_skip_types']) : "");
		COption::SetOptionString("bizproc", "automation_no_forced_tracking", ($_REQUEST['automation_no_forced_tracking'] ?? 'N') === "Y" ? "Y" : "N");
		COption::SetOptionString('bizproc', 'enable_getdocument_select', ($_REQUEST['enable_getdocument_select'] ?? 'N') === 'Y' ? 'Y' : 'N');
		COption::SetOptionString('bizproc', 'storage_item_data_limit', (string)(int)($_REQUEST['storage_item_data_limit'] ?? 1));

		$blockBufferMb = (string)($_REQUEST['storage_disk_block_buffer'] ?? '');
		$warningBufferMb = (string)($_REQUEST['storage_disk_warning_buffer'] ?? '');
		if (
			!StorageLimitsService::isValidDiskBufferMb($blockBufferMb)
			|| !StorageLimitsService::isValidDiskBufferMb($warningBufferMb)
		)
		{
			$strWarning .= GetMessage(
				'BIZPROC_OPT_STORAGE_DISK_BUFFER_RANGE_ERROR',
				['#MAX#' => (string)StorageLimitsService::MAX_DISK_BUFFER_MB],
			) ?? '';
		}
		elseif ((int)$warningBufferMb < (int)$blockBufferMb)
		{
			$strWarning .= GetMessage('BIZPROC_OPT_STORAGE_DISK_BUFFER_ORDER_ERROR') ?? '';
		}
		else
		{
			COption::SetOptionString('bizproc', 'storage_disk_block_buffer', (string)(int)$blockBufferMb);
			COption::SetOptionString('bizproc', 'storage_disk_warning_buffer', (string)(int)$warningBufferMb);
		}

		\Bitrix\Main\Config\Option::set("bizproc", "use_gzip_compression", $_REQUEST["use_gzip_compression"]);
		\Bitrix\Main\Config\Option::set("bizproc", "locked_wi_path", $_REQUEST["locked_wi_path"]);

		CBPSchedulerService::setDelayMinLimit($_REQUEST["delay_min_limit"], $_REQUEST['delay_min_limit_type']);

		$delayMaxDays = (int)($_REQUEST["delay_max_days"] ?? 0);
		CBPSchedulerService::setDelayMaxDays($delayMaxDays);

		$maxGraceDays = 365;
		$graceDaysInput = trim((string)($_REQUEST['clear_stuck_pause_grace_days'] ?? ''));
		$graceDays = (int)$graceDaysInput;
		if (preg_match('/^[0-9]+$/D', $graceDaysInput) !== 1 || $graceDays < 1 || $graceDays > $maxGraceDays)
		{
			$strWarning .= GetMessage(
				'BIZPROC_OPT_CLEAR_STUCK_PAUSE_GRACE_DAYS_RANGE_ERROR',
				['#MAX#' => (string)$maxGraceDays],
			) ?? '';
		}
		else
		{
			\Bitrix\Main\Config\Option::set('bizproc', 'clear_stuck_pause_grace_days', $graceDays);
		}

		// until the backfill of the resume messages is over the checkbox is disabled and submits nothing:
		// a save must not read that as turning the sweep off, nor as turning it on
		if (\Bitrix\Bizproc\Infrastructure\Agent\ClearStuckPauseWorkflowAgent::getActiveOptionValue() !== null)
		{
			if (($_REQUEST['clear_stuck_pause_agent_active'] ?? 'N') === 'Y')
			{
				\Bitrix\Bizproc\Infrastructure\Agent\ClearStuckPauseWorkflowAgent::register();
			}
			else
			{
				\Bitrix\Bizproc\Infrastructure\Agent\ClearStuckPauseWorkflowAgent::unregister();
			}
		}
		$clearZombieAgentName = \Bitrix\Bizproc\Infrastructure\Agent\ClearZombieInstanceAgent::next();
		if ($delayMaxDays > 0)
		{
			$nextTs = strtotime('tomorrow 01:00');
			CAgent::AddAgent(
				$clearZombieAgentName,
				'bizproc',
				next_exec: \ConvertTimeStamp($nextTs, 'FULL')
			);
		}
		else
		{
			CAgent::RemoveAgent($clearZombieAgentName, "bizproc");
		}

		foreach ($arSites as $site)
		{
			if (isset($_POST["name_template_" . $site["LID"]]))
			{
				if (empty($_POST["name_template_" . $site["LID"]]))
					COption::RemoveOption("bizproc", "name_template", $site["LID"]);
				else
					COption::SetOptionString("bizproc", "name_template", $_POST["name_template_" . $site["LID"]], false, $site["LID"]);
			}
		}
	}

	if ($strWarning <> '')
		CAdminMessage::ShowMessage($strWarning);

	$aTabs = [
		["DIV" => "edit1", "TAB" => GetMessage("BIZPROC_TAB_SET"), "ICON" => "", "TITLE" => GetMessage("BIZPROC_TAB_SET_ALT")],
	];
	$tabControl = new CAdminTabControl("tabControl", $aTabs);
	$tabControl->Begin();
	?>
	<form method="POST"
		name="bizproc_opt_form"
		action="<?= $APPLICATION->GetCurPage() ?>?mid=<?= htmlspecialcharsbx($_REQUEST['mid'] ?? 'bizproc') ?>&lang=<?= LANGUAGE_ID ?>"
		ENCTYPE="multipart/form-data"><?php
		echo bitrix_sessid_post();
		$tabControl->BeginNextTab();
		?>
		<?php
		for ($i = 0, $cnt = count($arAllOptions); $i < $cnt; $i++):
			$Option = $arAllOptions[$i];
			$val = COption::GetOptionString("bizproc", $Option[0], $Option[2]);
			$type = $Option[3];
			?>
			<tr>
				<td width="50%" valign="top"><?php
					if ($type[0] == "checkbox")
						echo "<label for=\"" . htmlspecialcharsbx($Option[0]) . "\">" . $Option[1] . "</label>";
					else
						echo $Option[1];
					?>:
				</td>
				<td width="50%" valign="top">
					<?php
					if ($type[0] == "checkbox"):?>
						<input type="checkbox" name="<?php
						echo htmlspecialcharsbx($Option[0]) ?>" id="<?php
						echo htmlspecialcharsbx($Option[0]) ?>" value="Y"<?php
						if ($val == "Y")
							echo " checked"; ?>>
					<?php
					elseif ($type[0] == "text"):?>
						<input type="text" size="<?php
						echo $type[1] ?>" value="<?php
						echo htmlspecialcharsbx($val) ?>" name="<?php
						echo htmlspecialcharsbx($Option[0]) ?>">
					<?php
					elseif ($type[0] == "textarea"):?>
						<textarea rows="<?php
						echo $type[1] ?>" cols="<?php
						echo $type[2] ?>" name="<?php
						echo htmlspecialcharsbx($Option[0]) ?>"><?php
							echo htmlspecialcharsbx($val) ?></textarea>
					<?php
					elseif ($type[0] == "checkboxlist"):?>
						<?php
						$arVal = explode(',', $val);
						?>
						<?php
						foreach ($type[1] as $k => $v):?>
							<input type="checkbox" name="<?php
							echo htmlspecialcharsbx($Option[0]) ?>[]" id="<?php
							echo htmlspecialcharsbx($Option[0] . '_' . $k) ?>" value="<?= $k ?>"<?php
							if (in_array($k, $arVal))
								echo " checked"; ?>>
							<label for="<?= htmlspecialcharsbx($Option[0] . '_' . $k) ?>"><?php
								echo htmlspecialcharsbx($v) ?></label><br>
						<?php
						endforeach; ?>
					<?php
					endif ?>
				</td>
			</tr>
		<?php
		endfor; ?>
		<tr>
			<td width="50%" valign="top"><?= GetMessage("BIZPROC_OPT_USE_GZIP_COMPRESSION") ?>:</td>
			<td width="50%" valign="top">
				<select name="use_gzip_compression">
					<?php
					$useGZipCompression = \Bitrix\Main\Config\Option::get("bizproc", "use_gzip_compression", ""); ?>
					<option value="" <?php
					if (empty($useGZipCompression))
						echo "selected"; ?>><?= GetMessage("BIZPROC_OPT_USE_GZIP_COMPRESSION_EMPTY") ?></option>
					<option value="Y" <?php
					if ($useGZipCompression == "Y")
						echo "selected"; ?>><?= GetMessage("BIZPROC_OPT_USE_GZIP_COMPRESSION_Y") ?></option>
					<option value="N" <?php
					if ($useGZipCompression == "N")
						echo "selected"; ?>><?= GetMessage("BIZPROC_OPT_USE_GZIP_COMPRESSION_N") ?></option>
				</select>
			</td>
		</tr>
		<tr>
			<td width="50%" valign="top"><?= GetMessage("BIZPROC_OPT_LOCKED_WI_PATH") ?>:</td>
			<td width="50%" valign="top">
				<?php
				$path = \Bitrix\Main\Config\Option::get("bizproc", "locked_wi_path", "/bizproc/bizproc/?type=is_locked"); ?>
				<input type="text" size="40" name="locked_wi_path" value="<?= htmlspecialcharsbx($path) ?>">
			</td>
		</tr>
		<tr>
			<td width="50%" valign="top"><?= GetMessage("BIZPROC_OPT_TIME_LIMIT") ?>:</td>
			<td width="50%" valign="top">
				<?php
				[$delayTime, $delayType] = CBPSchedulerService::getDelayMinLimit(true);
				?>
				<input type="text" name="delay_min_limit" value="<?= $delayTime ?>" size="5" />
				<select name="delay_min_limit_type">
					<option value="s"<?= ($delayType == "s") ? " selected" : "" ?>><?= GetMessage("BIZPROC_OPT_TIME_LIMIT_S") ?></option>
					<option value="m"<?= ($delayType == "m") ? " selected" : "" ?>><?= GetMessage("BIZPROC_OPT_TIME_LIMIT_M") ?></option>
					<option value="h"<?= ($delayType == "h") ? " selected" : "" ?>><?= GetMessage("BIZPROC_OPT_TIME_LIMIT_H") ?></option>
					<option value="d"<?= ($delayType == "d") ? " selected" : "" ?>><?= GetMessage("BIZPROC_OPT_TIME_LIMIT_D") ?></option>
				</select>
			</td>
		</tr>
		<tr>
			<td width="50%" valign="top"><?= GetMessage("BIZPROC_OPT_MAX_DAYS_LIMIT") ?>:</td>
			<td width="50%" valign="top">
				<input type="text" name="delay_max_days" value="<?= CBPSchedulerService::getDelayMaxDays() ?>" size="5" />
			</td>
		</tr>
		<tr>
			<td width="50%" valign="top">
				<label for="clear_stuck_pause_agent_active"><?= GetMessage("BIZPROC_OPT_CLEAR_STUCK_PAUSE_ACTIVE") ?></label>:
			</td>
			<td width="50%" valign="top">
				<?php
				$clearStuckPauseAgentActive = \Bitrix\Bizproc\Infrastructure\Agent\ClearStuckPauseWorkflowAgent::getActiveOptionValue();
				$isClearStuckPauseSettingAvailable = $clearStuckPauseAgentActive !== null; ?>
				<input type="checkbox" name="clear_stuck_pause_agent_active" id="clear_stuck_pause_agent_active" value="Y"<?php
				if ($clearStuckPauseAgentActive === 'Y')
					echo " checked";
				if (!$isClearStuckPauseSettingAvailable)
					echo " disabled"; ?>>
				<?php if (!$isClearStuckPauseSettingAvailable): ?>
					<span><?= GetMessage("BIZPROC_OPT_CLEAR_STUCK_PAUSE_ACTIVE_UPDATE_NOTE") ?></span>
				<?php endif; ?>
			</td>
		</tr>
		<tr>
			<td width="50%" valign="top"><?= GetMessage("BIZPROC_OPT_CLEAR_STUCK_PAUSE_GRACE_DAYS") ?>:</td>
			<td width="50%" valign="top">
				<?php
				$clearStuckPauseGraceDays = \Bitrix\Main\Config\Option::get(
					'bizproc',
					'clear_stuck_pause_grace_days',
					\Bitrix\Bizproc\Internal\Service\WorkflowState\StuckPauseWorkflowDetector::DEFAULT_GRACE_DAYS,
				); ?>
				<input type="text" name="clear_stuck_pause_grace_days" value="<?= (int)$clearStuckPauseGraceDays ?>" size="5" />
			</td>
		</tr>
		<tr>
			<td valign="top" colspan="2" align="center">
				<?php
				$subTabControl->Begin();
				foreach ($arSites as $site)
				{
					$subTabControl->BeginNextTab();
					$curVal = COption::GetOptionString("bizproc", "name_template", "", $site["LID"]);
					?>
					<label><?= GetMessage("BIZPROC_NAME_TEMPLATE") ?></label>:
					<select name="name_template_<?php
					echo $site["LID"] ?>">
					<?php
						$arNameTemplates = CSite::GetNameTemplates();
						$arNameTemplates = array_reverse($arNameTemplates, true); //prepend array with default '' => Site Format value
						$arNameTemplates[""] = GetMessage("BIZPROC_OPTIONS_NAME_IN_SITE_FORMAT");
						$arNameTemplates = array_reverse($arNameTemplates, true);
						foreach ($arNameTemplates as $template => $phrase)
						{
							$template = str_replace(["#NOBR#", "#/NOBR#"], ["", ""], $template);
							?>
							<option value="<?= $template ?>" <?= (($template == $curVal) ? " selected" : "") ?> ><?= $phrase ?></option><?php
						}
						?>
					</select>
					<?php
				}
				$subTabControl->End();
				?>
			</td>
		</tr>
		<?php
		$tabControl->Buttons(); ?>
		<script>
			function RestoreDefaults()
			{
				if (confirm('<?= AddSlashes(GetMessage("MAIN_HINT_RESTORE_DEFAULTS_WARNING"))?>'))
					window.location = "<?= $APPLICATION->GetCurPage() ?>?RestoreDefaults=Y&lang=<?= LANG ?>&mid=<?= urlencode($_REQUEST['mid'] ?? 'bizproc') ?>&<?= bitrix_sessid_get() ?>";
			}
		</script>

		<input type="submit" class="adm-btn-save" <?php
		if ($bizprocPerms < "W")
			echo "disabled" ?> name="Update" value="<?php
		echo GetMessage("MAIN_SAVE") ?>">
		<input type="hidden" name="Update" value="Y">
		<input type="reset" name="reset" value="<?php
		echo GetMessage("MAIN_RESET") ?>">
		<input type="button" <?php
		if ($bizprocPerms < "W")
			echo "disabled" ?> title="<?php
		echo GetMessage("MAIN_HINT_RESTORE_DEFAULTS") ?>" OnClick="RestoreDefaults();" value="<?php
		echo GetMessage("MAIN_RESTORE_DEFAULTS") ?>">
		<?php
		$tabControl->End(); ?>
	</form>
<?php
endif;
