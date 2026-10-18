<?php
/** @var CMain $APPLICATION */
/** @var CUser $USER */
/** @var array $biconnector_default_option */

use Bitrix\BIConnector\Integration\Superset\SelfHostedConnectionService;
use Bitrix\BIConnector\Integration\Superset\SupersetInitializer;
use Bitrix\BIConnector\Internal\Services\Concurrency\QuerySlotManager;
use Bitrix\BIConnector\Superset\Logger\Logger;
use Bitrix\BIConnector\Superset\Selfhost\License\LicenseLinks;
use Bitrix\BIConnector\Superset\Selfhost\License\SelfHostedAvailability;
use Bitrix\BIConnector\Superset\Selfhost\License\SelfHostedAvailabilityState;
use Bitrix\BIConnector\Superset\Selfhost\License\SelfHostedLicenseState;
use Bitrix\BIConnector\Superset\Selfhost\License\SelfHostedLicenseView;
use Bitrix\BIConnector\Superset\Selfhost\SupersetHostMode;
use Bitrix\Main\Application;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\ModuleManager;
use Bitrix\Main\Result;

$module_id = 'biconnector';
$canRead = $canWrite = $USER->IsAdmin();
if ($canWrite || $canRead)
{
	IncludeModuleLangFile($_SERVER['DOCUMENT_ROOT'] . BX_ROOT . '/modules/main/options.php');
	IncludeModuleLangFile(__FILE__);

	// The settings page is included without the module, so the module goes first: autoloading of its classes
	// starts working only after that, and the tab visibility below already asks one of them.
	CModule::IncludeModule($module_id);

	$allOptions = [
		['gds_deployment_id', Loc::getMessage('BIC_OPTIONS_GDS_DEPLOYMENT_ID'), ['text', '70']],
		['bi_builder_concurrency_limit', Loc::getMessage('BIC_OPTIONS_BI_BUILDER_CONCURRENCY_LIMIT'), ['text', '20']],
	];

	$isBoxed = !ModuleManager::isModuleInstalled('bitrix24');

	// Offered only where the connection is sold, but kept visible for a portal already switched to the local mode
	// whatever its area is: otherwise the way back to the cloud mode would disappear with the tab. Hiding is not
	// the restriction itself - that one lives on the actions, see SupersetHostMode::checkSelfHostedRestrictions().
	$isSelfHostedTabVisible =
		$isBoxed
		&& (
			SupersetHostMode::checkSelfHostedRegion()->isSuccess()
			|| SupersetHostMode::isSelfHosted()
		)
	;

	$settingsTabs = [
		[
			'DIV' => 'edit1',
			'TAB' => Loc::getMessage('MAIN_TAB_SET'),
			'TITLE' => Loc::getMessage('MAIN_TAB_TITLE_SET'),
		],
	];

	if ($isSelfHostedTabVisible)
	{
		$settingsTabs[] = [
			'DIV' => 'edit_superset',
			'TAB' => Loc::getMessage('BIC_TAB_SUPERSET'),
			'TITLE' => Loc::getMessage('BIC_TAB_SUPERSET_TITLE'),
		];
	}

	$settingsTabs[] = [
		'DIV' => 'edit2',
		'TAB' => Loc::getMessage('MAIN_TAB_RIGHTS'),
		'TITLE' => Loc::getMessage('MAIN_TAB_TITLE_RIGHTS'),
	];

	$tabControl = new CAdminTabControl('tabControl', $settingsTabs);

	$selfHostedConnectionService = new SelfHostedConnectionService();

	// AJAX: check superset version
	if (
		$isBoxed
		&& $_SERVER['REQUEST_METHOD'] === 'POST'
		&& isset($_REQUEST['action'])
		&& $_REQUEST['action'] === 'check_superset_version'
		&& $canWrite
		&& check_bitrix_sessid()
		&& Loader::includeModule('superset')
	)
	{
		$response = ['success' => false, 'version' => '', 'error' => ''];
		$version = $selfHostedConnectionService->getSupersetVersion(true);
		if ($version)
		{
			$response['success'] = true;
			$response['version'] = $version;
		}
		else
		{
			$response['error'] = Loc::getMessage('BIC_SUPERSET_VERSION_NO_SERVER');
		}

		header('Content-Type: application/json');
		echo \Bitrix\Main\Web\Json::encode($response);
		\CMain::FinalActions();
		die();
	}

	if (
		$_SERVER['REQUEST_METHOD'] === 'POST'
		&& isset($_REQUEST['action'])
		&& $_REQUEST['action'] === 'regenerate_jwt_keys'
		&& $canWrite
		&& check_bitrix_sessid()
		&& Loader::includeModule('superset')
	)
	{
		$response = ['success' => false, 'publicKey' => '', 'error' => ''];
		$generateResult = $selfHostedConnectionService->generateJwtKeys();
		if ($generateResult->isSuccess())
		{
			$response['success'] = true;
		}
		else
		{
			$response['error'] = $generateResult->getError()->getMessage();
		}

		header('Content-Type: application/json');
		echo \Bitrix\Main\Web\Json::encode($response);
		\CMain::FinalActions();
		die();
	}

	// A refusal is the result and not its message: a phrase missing in the language of the admin panel resolves to
	// nothing, so a refusal recognised by its text would read as "nothing was refused". The message is used for the
	// display only.
	$supersetModeResult = new Result();
	$supersetModeError = '';
	$requestedMode = '';
	$switchRequested = false;

	if (
		$_SERVER['REQUEST_METHOD'] === 'POST'
		&& (
			(isset($_REQUEST['Update']) && $_REQUEST['Update'] !== '')
			|| (isset($_REQUEST['Apply']) && $_REQUEST['Apply'] !== '')
			|| (isset($_REQUEST['RestoreDefaults']) && $_REQUEST['RestoreDefaults'] !== '')
		)
		&& $canWrite
		&& check_bitrix_sessid()
	)
	{
		include __DIR__ . '/default_option.php';

		foreach ($allOptions as $option)
		{
			$name = $option[0];
			$val = trim($_REQUEST[$name], " \t\n\r");
			if ($option[2][0] === 'checkbox' && $val !== 'Y')
			{
				$val = 'N';
			}

			if ($name === 'bi_builder_concurrency_limit' && $val !== '')
			{
				$val = (string)min(max((int)$val, 1), QuerySlotManager::MAX_SLOT_COUNT);
			}

			if ($val === $biconnector_default_option[$name])
			{
				Option::delete($module_id, ['name' => $name]);
			}
			else
			{
				Option::set($module_id, $name, $val);
			}
		}

		// Superset mode switching (boxed installations only): an unconfirmed request changes nothing.
		$requestedMode = $isBoxed ? (string)($_REQUEST['superset_mode'] ?? '') : '';
		$switchRequested =
			$requestedMode !== ''
			&& $requestedMode !== SupersetHostMode::getMode()
			&& ($_REQUEST['superset_mode_confirmed'] ?? 'N') === 'Y'
		;

		if ($switchRequested)
		{
			// Order matters: conditions of the switch, then the answer of the local server, and only then the data
			// of the mode being left. A server that does not answer leaves the portal in the mode it was in.
			$switchResult = SupersetHostMode::checkSwitchMode($requestedMode);
			if (!$switchResult->isSuccess())
			{
				$supersetModeResult->addErrors($switchResult->getErrors());
			}
			elseif ($requestedMode === SupersetHostMode::MODE_SELFHOSTED)
			{
				$newSupersetAddress = rtrim((string)($_REQUEST['superset_address'] ?? ''), '/');
				$newAdminPassword = (string)($_REQUEST['superset_admin_password'] ?? '');
				// Both are required for the first connection: there is no previously saved password to keep.
				if ($newSupersetAddress === '')
				{
					$supersetModeResult->addError(new Error(Loc::getMessage('BIC_SUPERSET_ADDRESS_REQUIRED')));
				}
				elseif ($newAdminPassword === '')
				{
					$supersetModeResult->addError(new Error(Loc::getMessage('BIC_SUPERSET_ADMIN_PASSWORD_REQUIRED')));
				}
				elseif (!Loader::includeModule('superset'))
				{
					$supersetModeResult->addError(new Error(Loc::getMessage('BIC_SUPERSET_MODULE_NOT_INSTALLED')));
				}
				else
				{
					$connectionResult = $selfHostedConnectionService->updateConnectionSettings(
						$newSupersetAddress,
						$newAdminPassword,
					);
					if ($connectionResult->isSuccess())
					{
						Application::getInstance()->getKernelSession()->set('BIC_SUPERSET_CONNECTION_SUCCESS', true);
					}
					else
					{
						Logger::logErrors($connectionResult->getErrors(), [
							'message' => 'Self-hosted Superset connection check before the mode switch failed',
							'superset_address' => $newSupersetAddress,
						]);
						$supersetModeResult->addError(new Error(Loc::getMessage('BIC_SUPERSET_CONNECTION_ERROR')));
					}
				}
			}

			if ($supersetModeResult->isSuccess())
			{
				// The switch refuses itself when the instance being left has not confirmed the reset of its license
				// state, and refuses before anything is destroyed: the page carries that verdict on as its own.
				$applyResult = SupersetHostMode::applySwitchMode($requestedMode);
				if (!$applyResult->isSuccess())
				{
					$supersetModeResult->addErrors($applyResult->getErrors());
				}
				elseif ($requestedMode === SupersetHostMode::MODE_SELFHOSTED)
				{
					// A connected server is already a working instance, so the switch itself makes it ready:
					// otherwise the portal would keep the "no instance" status the switch has just written.
					SupersetInitializer::startupSuperset();
				}
			}
		}

		// Address or password of an already connected server. A switch has done this itself, above.
		if (
			$isBoxed
			&& !$switchRequested
			&& (isset($_REQUEST['superset_address']) || isset($_REQUEST['superset_admin_password']))
			&& SupersetHostMode::getMode() === SupersetHostMode::MODE_SELFHOSTED
			&& Loader::includeModule('superset')
		)
		{
			$newSupersetAddress = rtrim((string)($_REQUEST['superset_address'] ?? ''), '/');
			// An empty password means "keep the saved one", so only the address is required: an empty one would
			// wipe the address of a working server.
			if ($newSupersetAddress === '')
			{
				$supersetModeResult->addError(new Error(Loc::getMessage('BIC_SUPERSET_ADDRESS_REQUIRED')));
			}
			else
			{
				$updateResult = $selfHostedConnectionService->updateConnectionSettings(
					$newSupersetAddress,
					(string)($_REQUEST['superset_admin_password'] ?? ''),
				);
				if (!$updateResult->isSuccess())
				{
					Logger::logErrors($updateResult->getErrors(), [
						'message' => 'Self-hosted Superset connection settings update failed',
						'superset_address' => $newSupersetAddress,
					]);
					$supersetModeResult->addError(new Error(Loc::getMessage('BIC_SUPERSET_CONNECTION_ERROR')));
				}
				else
				{
					if (!empty($updateResult->getData()['host_changed']))
					{
						Application::getInstance()->getKernelSession()->set('BIC_SUPERSET_CONNECTION_SUCCESS', true);
					}

					// The address of a working server has just been confirmed, so saving recovers a portal left
					// without a ready instance by a switch that stopped halfway.
					SupersetInitializer::startupSuperset();
				}
			}
		}

		// Save export row limit
		if ($isBoxed && isset($_REQUEST['selfhost_row_limit']))
		{
			$limitManager = \Bitrix\BIConnector\LimitManager::getInstance();
			$limitManager->setLimit((int)$_REQUEST['selfhost_row_limit']);
		}

		if ($supersetModeResult->isSuccess())
		{
			// The rights of the groups are saved by the file of the admin panel, and its output is of no use on a path
			// that ends with a redirect. On a refusal the same file is included by the rendering below and saves them
			// there, so it runs once whatever the path.
			ob_start();
			$Update = ($_REQUEST['Update'] ?? '') . ($_REQUEST['Apply'] ?? '');
			require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/admin/group_rights2.php';
			ob_end_clean();

			if ($_REQUEST['back_url_settings'] !== '')
			{
				if (isset($_REQUEST['Apply']) && $_REQUEST['Apply'] !== '')
				{
					LocalRedirect($APPLICATION->GetCurPage() . '?mid=' . urlencode($module_id) . '&lang=' . urlencode(LANGUAGE_ID) . '&back_url_settings=' . urlencode($_REQUEST['back_url_settings']) . '&' . $tabControl->ActiveTabParam());
				}
				else
				{
					LocalRedirect($_REQUEST['back_url_settings']);
				}
			}
			else
			{
				LocalRedirect($APPLICATION->GetCurPage() . '?mid=' . urlencode($module_id) . '&lang=' . urlencode(LANGUAGE_ID) . '&' . $tabControl->ActiveTabParam());
			}
		}
	}

	if (!$supersetModeResult->isSuccess())
	{
		$supersetModeError = (string)$supersetModeResult->getErrors()[0]->getMessage();
		if ($supersetModeError === '')
		{
			// The ru fallback is taken when the language of the admin panel has no phrase: a red box without
			// a word tells the administrator nothing.
			$supersetModeError = (string)(
				Loc::getMessage('BIC_SUPERSET_SAVE_ERROR')
				?? Loc::getMessage('BIC_SUPERSET_SAVE_ERROR', language: 'ru')
			);
		}
	}

	?>
	<style>
		/* A message box of the admin panel is an inline block, so it is centred by the cell, while its own
		   multiline text stays left aligned. */
		.bic-settings-message {
			text-align: center;
		}

		.bic-settings-message .adm-info-message {
			text-align: left;
		}
	</style>
	<form method="post" action="<?= $APPLICATION->GetCurPage() ?>?mid=<?= urlencode($module_id) ?>&amp;lang=<?= LANGUAGE_ID ?>">
	<?php
	$tabControl->Begin();
	$tabControl->BeginNextTab();

	foreach ($allOptions as $option)
	{
		$val = Option::get($module_id, $option[0]);
		$type = $option[2];
		?>
		<tr>
			<td width="40%" nowrap <?= ($type[0] === 'textarea') ? 'class="adm-detail-valign-top"' : '' ?>>
				<label for="<?= htmlspecialcharsbx($option[0]) ?>"><?= $option[1] ?>:</label>
			<td width="60%">
				<?php if ($type[0] === 'checkbox'): ?>
					<input type="checkbox" name="<?= htmlspecialcharsbx($option[0]) ?>" id="<?= htmlspecialcharsbx($option[0]) ?>" value="Y"<?= ($val === 'Y') ? ' checked' : '' ?>>
				<?php elseif ($type[0] === 'text'): ?>
					<input type="text" size="<?= $type[1] ?>" maxlength="255" value="<?= htmlspecialcharsbx($val) ?>" name="<?= htmlspecialcharsbx($option[0]) ?>" id="<?= htmlspecialcharsbx($option[0]) ?>">
				<?php elseif ($type[0] === 'textarea'): ?>
					<textarea rows="<?= $type[1] ?>" cols="<?= $type[2] ?>" name="<?= htmlspecialcharsbx($option[0]) ?>" id="<?= htmlspecialcharsbx($option[0]) ?>"><?= htmlspecialcharsbx($val) ?></textarea>
				<?php elseif ($type[0] === 'selectbox'): ?>
					<select name="<?= htmlspecialcharsbx($option[0]) ?>">
					<?php foreach ($type[1] as $key => $value): ?>
						<option value="<?= $key ?>"<?= ($val == $key) ? ' selected' : '' ?>><?= htmlspecialcharsbx($value) ?></option>
					<?php endforeach; ?>
					</select>
				<?php endif?>
			</td>
		</tr>
	<?php
	}

	// Rendered only when the tab header is offered: otherwise the tab control would get a body without a tab.
	if ($isSelfHostedTabVisible)
	{
		$tabControl->BeginNextTab();

		$currentMode = SupersetHostMode::getMode();
		$isSupersetModeRefused = !$supersetModeResult->isSuccess();
		$isSupersetInstalled = ModuleManager::isModuleInstalled('superset');
		$canSwitch = $isSupersetInstalled && SupersetHostMode::canSwitchMode();
		$disabled = $canSwitch ? '' : ' disabled';
		// The restrictions close the transition only: the mode already in use stays selectable, otherwise a
		// disabled choice would submit no mode and the connection settings could not be saved.
		$canSelectSelfHosted = $currentMode === SupersetHostMode::MODE_SELFHOSTED
			|| SupersetHostMode::checkSelfHostedRestrictions()->isSuccess()
		;
		$selfHostedDisabled = ($canSwitch && $canSelectSelfHosted) ? '' : ' disabled';
		// A refusal keeps the entered choice and address on the form, the confirmation included. A mode the
		// restrictions forbid is not kept: a choice both checked and disabled submits no mode at all.
		$isAttemptKept = $isSupersetModeRefused
			&& $switchRequested
			&& ($requestedMode !== SupersetHostMode::MODE_SELFHOSTED || $canSelectSelfHosted)
		;
		$selectedMode = $isAttemptKept ? $requestedMode : $currentMode;
		$switchConfirmed = $isAttemptKept ? 'Y' : 'N';
		$articleUrl = LicenseLinks::getDeployGuideUrl();

		// The state arrives resolved: the page displays it and decides nothing. Read in both modes, because the
		// expiry term is announced to a cloud portal administrator as well.
		$licenseViewSource = SelfHostedLicenseView::createForCurrentUser();
		$licenseView = $licenseViewSource->toArray();

		// Lines of a banner built from an availability state: its texts already exist for the working surfaces, so
		// the page reuses them. The call to action becomes a link and goes away without an address.
		$stateLines = static function (SelfHostedAvailabilityState $state, ?string $url): array
		{
			$title = $state->getTitle();
			// A state without a heading says everything in its description; an empty first line would read as a gap.
			$lines = $title === '' ? [] : [htmlspecialcharsbx($title)];
			$lines[] = htmlspecialcharsbx($state->getDescription());
			$actionText = $state->getActionText();
			if ($url !== null && $actionText !== null)
			{
				$actionNote = $state->getActionNote();
				$lines[] = '<a href="' . htmlspecialcharsbx($url) . '" target="_blank">'
					. htmlspecialcharsbx($actionText)
					. '</a>'
					. ($actionNote === null ? '' : ' ' . htmlspecialcharsbx($actionNote))
				;
			}

			return $lines;
		};

		// One banner at most for the whole chain of conditions, in the order of the gates: an unsuitable edition
		// first, because buying the extension changes nothing there, then the extension and only then its term.
		// The gate chain is not asked: outside the local mode it reports an available state whatever the license is.
		// The renewal phrases carry the link markup, so the address is escaped here, where it is substituted.
		$renewalUrl = LicenseLinks::getExtensionPurchaseUrl();
		$expiryBanner = null;
		if (!SupersetHostMode::checkSelfHostedEdition()->isSuccess())
		{
			$expiryBanner = [
				'testId' => 'biconnector-selfhost-switch-tariff',
				'isCritical' => false,
				'lines' => $stateLines(
					SelfHostedAvailabilityState::TariffUnavailable,
					LicenseLinks::getEnterprisePurchaseUrl(),
				),
			];
		}
		elseif (SelfHostedAvailability::getInstance()->isBoxLicenseExpired())
		{
			// Second gate, and it announces nothing: the portal itself reports the term of the box license right
			// above these settings, so a message of ours here would only repeat it. The branch stays to stop the
			// chain: while the box license is over, the extension is beside the point, so the banner about the
			// extension does not belong here either.
			$expiryBanner = null;
		}
		elseif ($licenseView['licenseState'] === SelfHostedLicenseState::Grace->value)
		{
			// The term is over and the work still runs: what matters here is the day it stops, so the banner names
			// both dates and stays in the alarming colour.
			$expiryBanner = [
				'testId' => 'biconnector-selfhost-license-expiry',
				'isCritical' => true,
				'lines' => [
					Loc::getMessage('BIC_SELFHOST_LICENSE_BANNER_GRACE', [
						'#DATE#' => htmlspecialcharsbx((string)$licenseView['expiryDate']),
					]),
					Loc::getMessage('BIC_SELFHOST_LICENSE_BANNER_GRACE_BLOCK', [
						'#BLOCK_DATE#' => htmlspecialcharsbx((string)$licenseView['blockDate']),
					]),
					$renewalUrl === null
						? Loc::getMessage('BIC_SELFHOST_LICENSE_BANNER_RENEWAL_NO_URL')
						: Loc::getMessage('BIC_SELFHOST_LICENSE_BANNER_RENEWAL', [
							'#PURCHASE_URL#' => htmlspecialcharsbx($renewalUrl),
						]),
				],
			];
		}
		elseif ($licenseView['licenseState'] === SelfHostedLicenseState::Expired->value)
		{
			$expiryBanner = [
				'testId' => 'biconnector-selfhost-license-expiry',
				'isCritical' => true,
				'lines' => [
					Loc::getMessage('BIC_SELFHOST_LICENSE_BANNER_EXPIRED', [
						'#DATE#' => htmlspecialcharsbx((string)$licenseView['expiryDate']),
					]),
					$renewalUrl === null
						? Loc::getMessage('BIC_SELFHOST_LICENSE_BANNER_EXPIRED_RENEWAL_NO_URL')
						: Loc::getMessage('BIC_SELFHOST_LICENSE_BANNER_EXPIRED_RENEWAL', [
							'#PURCHASE_URL#' => htmlspecialcharsbx($renewalUrl),
						]),
				],
			];
		}
		elseif ($licenseView['licenseState'] === SelfHostedLicenseState::None->value)
		{
			$expiryBanner = [
				'testId' => 'biconnector-selfhost-switch-extension',
				'isCritical' => false,
				'lines' => $stateLines(SelfHostedAvailabilityState::ExtensionMissing, $renewalUrl),
			];
		}
		elseif ($licenseView['expiryDate'] !== null)
		{
			// A license that still works stays in the calm colour even inside the warning window: the alarming one
			// is kept for a term that is already over. The window here is the one of an administrator - three
			// months - and not the one of the reports grid: ordering a renewal takes longer than reading a banner.
			$warningTexts = $licenseViewSource->getEarlyExpiryWarningTexts();
			$expiryBanner = $warningTexts === null
				? [
					'testId' => 'biconnector-selfhost-license-expiry',
					'isCritical' => false,
					'lines' => [
						Loc::getMessage('BIC_SELFHOST_LICENSE_BANNER_ACTIVE', [
							'#DATE#' => htmlspecialcharsbx($licenseView['expiryDate']),
						]),
					],
				]
				: [
					'testId' => 'biconnector-selfhost-license-expiry',
					'isCritical' => false,
					'lines' => [
						htmlspecialcharsbx($warningTexts['title']),
						htmlspecialcharsbx($warningTexts['description']),
						$renewalUrl === null
							? Loc::getMessage('BIC_SELFHOST_LICENSE_BANNER_RENEWAL_NO_URL')
							: Loc::getMessage('BIC_SELFHOST_LICENSE_BANNER_RENEWAL', [
								'#PURCHASE_URL#' => htmlspecialcharsbx($renewalUrl),
							]),
					],
				]
			;
		}

		$kernelSession = Application::getInstance()->getKernelSession();
		$supersetIsConnectSuccess = (bool)$kernelSession->get('BIC_SUPERSET_CONNECTION_SUCCESS');
		if ($supersetIsConnectSuccess)
		{
			$kernelSession->remove('BIC_SUPERSET_CONNECTION_SUCCESS');
		}

		if ($isSupersetModeRefused)
		{
			?>
			<tr>
				<td colspan="2" class="bic-settings-message">
					<div class="adm-info-message-wrap adm-info-message-red">
						<div class="adm-info-message" role="alert" data-testid="biconnector-selfhost-save-error">
							<?= htmlspecialcharsbx($supersetModeError) ?>
							<div class="adm-info-message-icon"></div>
						</div>
					</div>
				</td>
			</tr>
			<?php
		}
		elseif ($supersetIsConnectSuccess)
		{
			?>
			<tr>
				<td colspan="2" class="bic-settings-message">
					<div class="adm-info-message-wrap adm-info-message-green">
						<div class="adm-info-message" role="status" data-testid="biconnector-selfhost-connection-success">
							<?= Loc::getMessage('BIC_SUPERSET_CONNECTION_SUCCESS') ?>
							<div class="adm-info-message-icon"></div>
						</div>
					</div>
				</td>
			</tr>
			<?php
		}

		if (!$isSupersetInstalled)
		{
			?>
			<tr>
				<td colspan="2" class="bic-settings-message">
					<div class="adm-info-message" data-testid="biconnector-selfhost-module-missing">
						<?= Loc::getMessage('BIC_SUPERSET_MODULE_NOT_INSTALLED') ?>
					</div>
				</td>
			</tr>
			<?php
		}
			?>
		<?php if ($expiryBanner !== null): ?>
		<tr>
			<td colspan="2" class="bic-settings-message">
				<?php if ($expiryBanner['isCritical']): ?>
				<div class="adm-info-message-wrap adm-info-message-red">
					<div class="adm-info-message" data-testid="<?= $expiryBanner['testId'] ?>">
						<?= implode('<br>', $expiryBanner['lines']) ?>
						<div class="adm-info-message-icon"></div>
					</div>
				</div>
				<?php else: ?>
				<div class="adm-info-message" data-testid="<?= $expiryBanner['testId'] ?>">
					<?= implode('<br>', $expiryBanner['lines']) ?>
				</div>
				<?php endif ?>
			</td>
		</tr>
		<?php endif ?>
		<tr>
			<td width="40%" id="superset_mode_label"><?= Loc::getMessage('BIC_SUPERSET_MODE') ?>:</td>
			<td width="60%">
				<?php // The choice is announced as a group: on its own a radio does not say what is being chosen. ?>
				<div role="radiogroup" aria-labelledby="superset_mode_label" data-testid="biconnector-selfhost-mode">
					<label>
						<input type="radio" name="superset_mode" value="<?= SupersetHostMode::MODE_CLOUD ?>"<?= ($selectedMode === SupersetHostMode::MODE_CLOUD) ? ' checked' : '' ?><?= $disabled ?> onclick="bicOnModeChange(this, false)" data-testid="biconnector-selfhost-mode-cloud">
						<?= Loc::getMessage('BIC_SUPERSET_MODE_CLOUD') ?>
					</label>
					<br>
					<label>
						<input type="radio" name="superset_mode" value="<?= SupersetHostMode::MODE_SELFHOSTED ?>"<?= ($selectedMode === SupersetHostMode::MODE_SELFHOSTED) ? ' checked' : '' ?><?= $selfHostedDisabled ?> onclick="bicOnModeChange(this, true)" data-testid="biconnector-selfhost-mode-selfhosted">
						<?= Loc::getMessage('BIC_SUPERSET_MODE_SELFHOSTED') ?>
					</label>
				</div>
				<input type="hidden" name="superset_mode_confirmed" id="superset_mode_confirmed" value="<?= $switchConfirmed ?>" data-testid="biconnector-selfhost-mode-confirmed">
			</td>
		</tr>
		<?php
		$supersetAddress = '';
		$jwtPublicKey = '';
		if (Loader::includeModule('superset'))
		{
			$supersetAddress = $selfHostedConnectionService->getSupersetHost();
			$jwtPublicKey = $selfHostedConnectionService->readJwtPublicKey();
		}
		if ($isSupersetModeRefused && isset($_REQUEST['superset_address']))
		{
			$supersetAddress = rtrim((string)($_REQUEST['superset_address']), '/');
		}
		$selfhostedDisplay = ($selectedMode !== SupersetHostMode::MODE_SELFHOSTED) ? 'display:none' : '';
		?>
		<tr class="superset-selfhosted-settings" style="<?= $selfhostedDisplay ?>">
			<td colspan="2" class="bic-settings-message">
				<div class="adm-info-message" data-testid="biconnector-selfhost-deploy-hint">
					<?php // Raw output is left to the phrase below, the one that carries the link. ?>
					<?= htmlspecialcharsbx((string)Loc::getMessage('BIC_SUPERSET_SELFHOSTED_DEPLOY_INTRO')) ?>
					<?php if ($articleUrl !== null): ?>
					<?= Loc::getMessage('BIC_SUPERSET_SELFHOSTED_DEPLOY_HINT_MSGVER_1', [
						'#ARTICLE_URL#' => htmlspecialcharsbx($articleUrl),
					]) ?>
					<?php endif ?>
				</div>
			</td>
		</tr>
		<tr class="superset-selfhosted-settings" style="<?= $selfhostedDisplay ?>">
			<td width="40%" nowrap>
				<label for="superset_address"><?= Loc::getMessage('BIC_SUPERSET_ADDRESS') ?>:</label>
			</td>
			<td width="60%">
				<?php // Required while the rows are shown, so the requirement appears and goes away together with them. ?>
				<input type="text" size="70" maxlength="255" value="<?= htmlspecialcharsbx($supersetAddress) ?>" name="superset_address" id="superset_address"<?= ($selfhostedDisplay === '') ? ' required' : '' ?> data-testid="biconnector-selfhost-address-input">
			</td>
		</tr>
		<tr class="superset-selfhosted-settings" style="<?= $selfhostedDisplay ?>">
			<td width="40%" nowrap>
				<label for="superset_admin_password"><?= Loc::getMessage('BIC_SUPERSET_ADMIN_PASSWORD') ?>:</label>
			</td>
			<td width="60%">
				<input type="password" size="40" maxlength="255" value="" name="superset_admin_password" id="superset_admin_password" autocomplete="off" aria-describedby="superset_admin_password_hint" data-testid="biconnector-selfhost-admin-password-input">
				<br><small id="superset_admin_password_hint"><?= Loc::getMessage('BIC_SUPERSET_ADMIN_PASSWORD_HINT') ?></small>
			</td>
		</tr>
		<tr class="superset-selfhosted-settings" style="<?= $selfhostedDisplay ?>">
			<td width="40%" nowrap class="adm-detail-valign-top">
				<label for="superset_regenerate_bi_token_btn"><?= Loc::getMessage('BIC_SUPERSET_BI_TOKEN') ?>:</label>
			</td>
			<td width="60%">
				<input type="button" id="superset_regenerate_bi_token_btn" value="<?= htmlspecialcharsbx(Loc::getMessage('BIC_SUPERSET_BI_TOKEN_REGENERATE')) ?>" onclick="bicRegenerateBiToken()" aria-describedby="superset_bi_token_hint" data-testid="biconnector-selfhost-bi-token-regenerate-btn">
				<?php // The answer of the server arrives without a focus change, so the place it appears in is announced. ?>
				<span id="superset_bi_token_regenerate_result" role="status" data-testid="biconnector-selfhost-bi-token-result"></span>
				<br><small id="superset_bi_token_hint"><?= Loc::getMessage('BIC_SUPERSET_BI_TOKEN_HINT') ?></small>
			</td>
		</tr>
		<tr class="superset-selfhosted-settings" style="<?= $selfhostedDisplay ?>">
			<td width="40%" nowrap class="adm-detail-valign-top">
				<label for="superset_regenerate_jwt_btn"><?= Loc::getMessage('BIC_SUPERSET_JWT_PUBLIC_KEY') ?>:</label>
			</td>
			<td width="60%">
				<input type="button" id="superset_regenerate_jwt_btn" value="<?= htmlspecialcharsbx(Loc::getMessage('BIC_SUPERSET_JWT_REGENERATE')) ?>" onclick="bicRegenerateJwtKeys()" aria-describedby="superset_jwt_public_key_hint" data-testid="biconnector-selfhost-jwt-regenerate-btn">
				<span id="superset_jwt_regenerate_result" role="status" data-testid="biconnector-selfhost-jwt-result"></span>
				<br><small id="superset_jwt_public_key_hint"><?= Loc::getMessage('BIC_SUPERSET_JWT_PUBLIC_KEY_HINT') ?></small>
			</td>
		</tr>
		<tr class="superset-selfhosted-settings" style="<?= $selfhostedDisplay ?>">
			<td width="40%" nowrap>
				<label for="selfhost_row_limit"><?= Loc::getMessage('BIC_OPTIONS_EXPORT_ROW_LIMIT') ?>:</label>
			</td>
			<td width="60%">
				<input type="text" size="20" maxlength="15" value="<?= htmlspecialcharsbx(Option::get($module_id, 'selfhost_row_limit', \Bitrix\BIConnector\LimitManagerBox::DEFAULT_SELFHOST_LIMIT)) ?>" name="selfhost_row_limit" id="selfhost_row_limit" aria-describedby="selfhost_row_limit_hint" data-testid="biconnector-selfhost-row-limit-input">
				<br><small id="selfhost_row_limit_hint"><?= Loc::getMessage('BIC_OPTIONS_EXPORT_ROW_LIMIT_HINT') ?></small>
			</td>
		</tr>
		<tr class="superset-selfhosted-settings" style="<?= $selfhostedDisplay ?>">
			<td width="40%" nowrap>
				<label for="superset_check_version_btn"><?= Loc::getMessage('BIC_SUPERSET_VERSION') ?>:</label>
			</td>
			<td width="60%">
				<input type="button" id="superset_check_version_btn" value="<?= htmlspecialcharsbx(Loc::getMessage('BIC_SUPERSET_CHECK_VERSION')) ?>" onclick="bicSupersetCheckVersion()" data-testid="biconnector-selfhost-version-check-btn">
				<span id="superset_version_result" role="status" data-testid="biconnector-selfhost-version-result"></span>
			</td>
		</tr>
		<?php
	}

	if ($canWrite)
	{
		$tabControl->BeginNextTab();
		require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/admin/group_rights2.php';
	}

	?>
	<?php $tabControl->Buttons();?>
		<input <?= (!$canWrite) ? 'disabled' : '' ?> type="submit" name="Update" value="<?= Loc::getMessage('MAIN_SAVE') ?>" title="<?= Loc::getMessage('MAIN_OPT_SAVE_TITLE') ?>" class="adm-btn-save">
		<input <?= (!$canWrite) ? 'disabled' : '' ?> type="submit" name="Apply" value="<?= Loc::getMessage('MAIN_OPT_APPLY') ?>" title="<?= Loc::getMessage('MAIN_OPT_APPLY_TITLE') ?>">
		<?php if ($_REQUEST['back_url_settings'] !== ''): ?>
			<input <?= (!$canWrite) ? 'disabled' : '' ?> type="button" name="Cancel" value="<?= Loc::getMessage('MAIN_OPT_CANCEL') ?>" title="<?= Loc::getMessage('MAIN_OPT_CANCEL_TITLE') ?>" onclick="window.location='<?= htmlspecialcharsbx(CUtil::addslashes($_REQUEST['back_url_settings'])) ?>'">
			<input type="hidden" name="back_url_settings" value="<?= htmlspecialcharsbx($_REQUEST['back_url_settings']) ?>">
		<?php endif?>
		<?= bitrix_sessid_post() ?>
	<?php $tabControl->End();?>
	</form>
	<?php if ($isSelfHostedTabVisible): ?>
	<script>
	var bicOriginalMode = '<?= CUtil::JSEscape($currentMode) ?>';
	// Each direction names the data it destroys: only the switch to the local mode loses the cloud BI data.
	var bicModeSwitchConfirmToSelfhosted = '<?= CUtil::JSEscape(Loc::getMessage('BIC_SUPERSET_MODE_SWITCH_CONFIRM_SELFHOSTED')) ?>';
	var bicModeSwitchConfirmToCloud = '<?= CUtil::JSEscape(Loc::getMessage('BIC_SUPERSET_MODE_SWITCH_CONFIRM_MSGVER_1')) ?>';

	function bicOnModeChange(radio, showSelfhosted)
	{
		if (radio.value !== bicOriginalMode)
		{
			var confirmMessage = showSelfhosted ? bicModeSwitchConfirmToSelfhosted : bicModeSwitchConfirmToCloud;
			if (!confirm(confirmMessage))
			{
				document.querySelector('input[name="superset_mode"][value="' + bicOriginalMode + '"]').checked = true;
				return;
			}
			document.getElementById('superset_mode_confirmed').value = 'Y';
		}
		bicToggleSupersetSettings(showSelfhosted);
	}

	function bicToggleSupersetSettings(show)
	{
		var rows = document.querySelectorAll('.superset-selfhosted-settings');
		for (var i = 0; i < rows.length; i++)
		{
			rows[i].style.display = show ? '' : 'none';
		}
		// The requirement follows the visibility: a hidden required field would block saving without a word.
		document.getElementById('superset_address').required = show;
	}

	function bicSupersetCheckVersion()
	{
		var btn = document.getElementById('superset_check_version_btn');
		var resultSpan = document.getElementById('superset_version_result');

		btn.disabled = true;
		resultSpan.innerText = '<?= CUtil::JSEscape(Loc::getMessage('BIC_SUPERSET_VERSION_LOADING')) ?>';
		resultSpan.style.color = '';

		var formData = new FormData();
		formData.append('action', 'check_superset_version');
		formData.append('sessid', BX.bitrix_sessid());

		fetch('<?= CUtil::JSEscape($APPLICATION->GetCurPage()) ?>?mid=<?= CUtil::JSEscape(urlencode($module_id)) ?>&lang=<?= CUtil::JSEscape(LANGUAGE_ID) ?>', {
			method: 'POST',
			body: formData
		})
		.then(function(response) { return response.json(); })
		.then(function(data) {
			btn.disabled = false;
			if (data.success)
			{
				resultSpan.style.color = 'green';
				resultSpan.innerText = data.version;
			}
			else
			{
				resultSpan.style.color = 'red';
				resultSpan.innerText = data.error || '<?= CUtil::JSEscape(Loc::getMessage('BIC_SUPERSET_VERSION_ERROR')) ?>';
			}
		})
		.catch(function() {
			btn.disabled = false;
			resultSpan.style.color = 'red';
			resultSpan.innerText = '<?= CUtil::JSEscape(Loc::getMessage('BIC_SUPERSET_VERSION_ERROR')) ?>';
		});
	}

	function bicRegenerateBiToken()
	{
		var btn = document.getElementById('superset_regenerate_bi_token_btn');
		var resultSpan = document.getElementById('superset_bi_token_regenerate_result');

		btn.disabled = true;
		resultSpan.innerText = '<?= CUtil::JSEscape(Loc::getMessage('BIC_SUPERSET_BI_TOKEN_REGENERATE_LOADING')) ?>';
		resultSpan.style.color = '';

		BX.ajax.runAction('biconnector.key.changeSupersetKey')
			.then(function() {
				btn.disabled = false;
				resultSpan.style.color = 'green';
				resultSpan.innerText = '<?= CUtil::JSEscape(Loc::getMessage('BIC_SUPERSET_BI_TOKEN_REGENERATE_SUCCESS')) ?>';
			})
			.catch(function() {
				btn.disabled = false;
				resultSpan.style.color = 'red';
				resultSpan.innerText = '<?= CUtil::JSEscape(Loc::getMessage('BIC_SUPERSET_BI_TOKEN_REGENERATE_ERROR')) ?>';
			});
	}

	function bicRegenerateJwtKeys()
	{
		var btn = document.getElementById('superset_regenerate_jwt_btn');
		var resultSpan = document.getElementById('superset_jwt_regenerate_result');

		btn.disabled = true;
		resultSpan.innerText = '<?= CUtil::JSEscape(Loc::getMessage('BIC_SUPERSET_JWT_REGENERATE_LOADING')) ?>';
		resultSpan.style.color = '';

		var formData = new FormData();
		formData.append('action', 'regenerate_jwt_keys');
		formData.append('sessid', BX.bitrix_sessid());

		fetch('<?= CUtil::JSEscape($APPLICATION->GetCurPage()) ?>?mid=<?= CUtil::JSEscape(urlencode($module_id)) ?>&lang=<?= CUtil::JSEscape(LANGUAGE_ID) ?>', {
			method: 'POST',
			body: formData
		})
		.then(function(response) { return response.json(); })
		.then(function(data) {
			btn.disabled = false;
			if (data.success)
			{
				resultSpan.style.color = 'green';
				resultSpan.innerText = '<?= CUtil::JSEscape(Loc::getMessage('BIC_SUPERSET_JWT_REGENERATE_SUCCESS')) ?>';
			}
			else
			{
				resultSpan.style.color = 'red';
				resultSpan.innerText = data.error || '<?= CUtil::JSEscape(Loc::getMessage('BIC_SUPERSET_JWT_REGENERATE_ERROR')) ?>';
			}
		})
		.catch(function() {
			btn.disabled = false;
			resultSpan.style.color = 'red';
			resultSpan.innerText = '<?= CUtil::JSEscape(Loc::getMessage('BIC_SUPERSET_JWT_REGENERATE_ERROR')) ?>';
		});
	}
	</script>
	<?php endif; ?>
	<?php
}
