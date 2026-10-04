<?php

declare(strict_types=1);

use Bitrix\Main\Application;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\Loader;
use Bitrix\Main\Localization\Loc;
use Bitrix\Vibecodeconnector\Internal\Exception\InvalidEndpointUrlException;
use Bitrix\Vibecodeconnector\Internal\Exception\PublicKeyFetchFailedException;
use Bitrix\Vibecodeconnector\Internal\Exception\RegistrationFailedException;
use Bitrix\Vibecodeconnector\Internal\Repository\Pairing\PairingRepository;
use Bitrix\Vibecodeconnector\Internal\Service\Bot\BotService;
use Bitrix\Vibecodeconnector\Internal\Service\Catalog\OpenApp\OpenAppSettings;
use Bitrix\Vibecodeconnector\Internal\Service\Diagnostic\CloudSharedKeyLog;
use Bitrix\Vibecodeconnector\Internal\Service\Diagnostic\IncomingJwtLog;
use Bitrix\Vibecodeconnector\Internal\Service\Endpoint\BaseEndpointProvider;
use Bitrix\Vibecodeconnector\Internal\Service\Endpoint\CloudEndpointProvider;
use Bitrix\Vibecodeconnector\Internal\Service\Endpoint\EndpointUrlGuard;
use Bitrix\Vibecodeconnector\Internal\Service\PublicKey\CloudKeySourceSettings;
use Bitrix\Vibecodeconnector\Internal\Service\PublicKey\CloudSharedKeyProvisioner;
use Bitrix\Vibecodeconnector\Internal\Service\PublicKey\CloudSharedKeyStore;
use Bitrix\Vibecodeconnector\Internal\Service\PublicKey\PairingKeyRefresher;
use Bitrix\Vibecodeconnector\Internal\Service\PublicKey\PublicKeySource;
use Bitrix\Vibecodeconnector\Internal\Service\Registration\PairingSettings;
use Bitrix\Vibecodeconnector\Internal\Service\Registration\RegistrationService;
use Bitrix\Vibecodeconnector\Public\Service\AvailabilityService;
use Bitrix\Vibecodeconnector\Internal\Service\Provisioning\PermissionSource\Policy as PermissionSourcePolicy;
use Bitrix\Vibecodeconnector\Internal\Service\Provisioning\PermissionSource\Settings as PermissionSourceSettings;

/**
 * @global CMain $APPLICATION
 */

$module_id = 'vibecodeconnector';

if (!Loader::includeModule($module_id))
{
	return;
}

$moduleAccess = $APPLICATION->GetGroupRight($module_id);
if ($moduleAccess < 'R')
{
	$APPLICATION->AuthForm(Loc::getMessage('ACCESS_DENIED'));

	return;
}

Loc::loadMessages($_SERVER['DOCUMENT_ROOT'] . BX_ROOT . '/modules/main/options.php');
Loc::loadMessages(__FILE__);

$request = Application::getInstance()->getContext()->getRequest();
$serviceCache = [];
$service = static function (string $class) use (&$serviceCache) {
	return $serviceCache[$class] ??= ServiceLocator::getInstance()->get($class);
};
$baseEndpointProvider = ServiceLocator::getInstance()->get(BaseEndpointProvider::class);
$cloudEndpointProvider = ServiceLocator::getInstance()->get(CloudEndpointProvider::class);
$registrationService = ServiceLocator::getInstance()->get(RegistrationService::class);
$availabilityService = ServiceLocator::getInstance()->get(AvailabilityService::class);
$permissionSourceSettings = ServiceLocator::getInstance()->get(PermissionSourceSettings::class);
$permissionSourcePolicy = ServiceLocator::getInstance()->get(PermissionSourcePolicy::class);
$openAppSettings = ServiceLocator::getInstance()->get(OpenAppSettings::class);
$pairingKeyRefresher = ServiceLocator::getInstance()->get(PairingKeyRefresher::class);
$pairingSettings = ServiceLocator::getInstance()->get(PairingSettings::class);
$cloudSharedKeyProvisioner = ServiceLocator::getInstance()->get(CloudSharedKeyProvisioner::class);
$cloudKeySourceSettings = ServiceLocator::getInstance()->get(CloudKeySourceSettings::class);
$pairingRepository = ServiceLocator::getInstance()->get(PairingRepository::class);
$botService = ServiceLocator::getInstance()->get(BotService::class);
$showPublicKeySource = false;

$tabs = [
	[
		'DIV' => 'tab_settings',
		'TAB' => Loc::getMessage('VIBECODECONNECTOR_OPT_TAB_SETTINGS_V2'),
		'TITLE' => Loc::getMessage('VIBECODECONNECTOR_OPT_TAB_SETTINGS_V2_TITLE'),
	],
	[
		'DIV' => 'tab_diagnostics',
		'TAB' => Loc::getMessage('VIBECODECONNECTOR_OPT_TAB_DIAGNOSTICS'),
		'TITLE' => Loc::getMessage('VIBECODECONNECTOR_OPT_TAB_DIAGNOSTICS_TITLE'),
	],
	[
		'DIV' => 'tab_rights',
		'TAB' => Loc::getMessage('MAIN_TAB_RIGHTS'),
		'TITLE' => Loc::getMessage('MAIN_TAB_TITLE_RIGHTS'),
	],
];

$tabControl = new CAdminTabControl('tabControl', $tabs);

$registrationError = null;
$registrationSuccess = false;
$unregisterSuccess = false;
$unregisterError = null;
$refreshSuccess = false;
$refreshError = null;
$cloudSharedRefreshSuccess = false;
$cloudSharedRefreshError = null;
$baseUrlError = null;
$pairingSourceSwitched = false;

if (
	$request->getRequestMethod() === 'POST'
	&& $moduleAccess >= 'W'
	&& check_bitrix_sessid()
)
{
	$action = (string)$request->getPost('action');

	if ($action === 'Update')
	{
		$newBaseUrl = trim((string)$request->getPost('endpoint_base_url'));
		$oldBaseUrl = $baseEndpointProvider->getBaseUrl();

		$baseUrlChanged = false;
		// The address is always typed by hand: the list on the page only fills the field,
		// so the only gate here is that the address itself is valid. Clearing the field is
		// a change too, and the guard must reject it instead of the page ignoring it.
		if (rtrim($newBaseUrl, '/') !== rtrim($oldBaseUrl, '/'))
		{
			try
			{
				$service(EndpointUrlGuard::class)->assertValid($newBaseUrl);
				$baseEndpointProvider->setBaseUrl($newBaseUrl);
				$baseUrlChanged = true;
			}
			catch (InvalidEndpointUrlException $e)
			{
				$baseUrlError = $e->getMessage();
			}
		}

		$availabilityService->setEnabled($request->getPost('is_ready') !== null);
		$openAppSettings->setOpenInIframeEnabled($request->getPost('open_app_in_iframe') !== null);

		$permissionSourceSettings->setVibecodeSource($request->getPost('permission_source') !== null);
		$ttlInput = trim((string)$request->getPost('pairing_max_ttl_seconds'));
		if ($ttlInput !== '')
		{
			$pairingSettings->setMaxTtlSeconds((int)$ttlInput);
		}

		if ($baseUrlChanged)
		{
			$botService->refreshOutboundWebhook();
		}

		ob_start();
		require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/admin/group_rights.php';
		ob_end_clean();

		// A rejected address must stay on the page: a redirect would drop the message.
		if ($baseUrlError === null)
		{
			$backUrl = (string)$request->get('back_url_settings');
			if ($backUrl !== '')
			{
				LocalRedirect($backUrl);
			}

			LocalRedirect(
				$APPLICATION->GetCurPage()
				. '?mid=' . urlencode($module_id)
				. '&lang=' . urlencode(LANGUAGE_ID)
				. '&' . $tabControl->ActiveTabParam()
			);
		}
	}
	elseif ($action === 'Register')
	{
		$registerUrl = trim((string)$request->getPost('register_endpoint_url'));
		if ($registerUrl === '')
		{
			$registerUrl = $baseEndpointProvider->getBaseUrl();
		}

		$wasRegisteredBefore = $registrationService->isRegistered();
		$newPairing = null;
		try
		{
			$newPairing = $registrationService->register($registerUrl);
			$registrationSuccess = true;
		}
		catch (InvalidEndpointUrlException $e)
		{
			$registrationError = [
				'code' => (string)($e->getErrorCode() ?? ''),
				'message' => $e->getMessage(),
			];
		}
		catch (RegistrationFailedException $e)
		{
			$registrationError = [
				'code' => (string)($e->getErrorCode() ?? ''),
				'message' => $e->getMessage(),
			];
		}
		catch (\Throwable $e)
		{
			$registrationError = [
				'code' => '',
				'message' => $e->getMessage(),
			];
		}

		if ($registrationSuccess && $newPairing !== null && !$wasRegisteredBefore)
		{
			$baseEndpointProvider->setBaseUrl($newPairing->endpointUrl);
			$botService->refreshOutboundWebhook();
		}
	}
	elseif ($action === 'UnregisterIss')
	{
		$iss = trim((string)$request->getPost('iss'));
		if ($iss !== '')
		{
			$pairingsBefore = $registrationService->listPairings();
			$removedEndpoint = null;
			foreach ($pairingsBefore as $p)
			{
				if ($p->iss === $iss)
				{
					$removedEndpoint = rtrim($p->endpointUrl, '/');
					break;
				}
			}
			$wasActive = $removedEndpoint !== null
				&& $removedEndpoint === rtrim($baseEndpointProvider->getBaseUrl(), '/');

			try
			{
				$registrationService->unregister($iss);
				$unregisterSuccess = true;
			}
			catch (\Throwable $e)
			{
				$unregisterError = $e->getMessage();
			}

			if ($unregisterSuccess)
			{
				$remaining = $registrationService->listPairings();

				if (empty($remaining))
				{
					try
					{
						$botService->uninstall();
					}
					catch (\Throwable $e)
					{
						$unregisterError = $e->getMessage();
					}
				}
				elseif ($wasActive)
				{
					$baseEndpointProvider->setBaseUrl($remaining[0]->endpointUrl);
					$botService->refreshOutboundWebhook();
				}
			}
		}
	}
	elseif ($action === 'RefreshIss')
	{
		$iss = trim((string)$request->getPost('iss'));
		if ($iss !== '')
		{
			try
			{
				$pairingKeyRefresher->refresh($iss);
				$refreshSuccess = true;
			}
			catch (PublicKeyFetchFailedException $e)
			{
				$refreshError = $e->getMessage();
			}
			catch (\Throwable $e)
			{
				$refreshError = $e->getMessage();
			}
		}
	}
	elseif ($action === 'RefreshCloudSharedKey')
	{
		try
		{
			$cloudSharedKeyProvisioner->provisionManually($service(CloudSharedKeyStore::class)->get());
			$cloudSharedRefreshSuccess = true;
		}
		catch (PublicKeyFetchFailedException $e)
		{
			$cloudSharedRefreshError = $e->getMessage();
		}
		catch (\Throwable $e)
		{
			$cloudSharedRefreshError = $e->getMessage();
		}
	}
	elseif ($action === 'SaveAndRefreshCloudSharedKey')
	{
		$newCloudUrl = trim((string)$request->getPost('cloud_shared_endpoint_url'));

		$urlAccepted = true;
		if ($newCloudUrl === '')
		{
			$cloudEndpointProvider->clearCloudUrl();
		}
		else
		{
			try
			{
				$service(EndpointUrlGuard::class)->assertValid($newCloudUrl);
				$cloudEndpointProvider->setCloudUrl($newCloudUrl);
			}
			catch (InvalidEndpointUrlException $e)
			{
				$cloudSharedRefreshError = $e->getMessage();
				$urlAccepted = false;
			}
		}

		if ($urlAccepted)
		{
			$cloudKeySourceSettings->setSource(
				PublicKeySource::tryFromOrDefault((string)$request->getPost('cloud_shared_key_source')),
			);

			try
			{
				$cloudSharedKeyProvisioner->provisionManually($service(CloudSharedKeyStore::class)->get());
				$cloudSharedRefreshSuccess = true;
			}
			catch (PublicKeyFetchFailedException $e)
			{
				$cloudSharedRefreshError = $e->getMessage();
			}
			catch (\Throwable $e)
			{
				$cloudSharedRefreshError = $e->getMessage();
			}
		}
	}
	elseif ($action === 'SwitchPairingKeySource')
	{
		$iss = trim((string)$request->getPost('iss'));
		$source = PublicKeySource::tryFromOrDefault((string)$request->getPost('key_source'));
		if ($iss !== '')
		{
			$pairingRepository->updateSourceByIss($iss, $source);
			$pairingSourceSwitched = true;
		}
	}
}

$endpointBaseUrl = $baseEndpointProvider->getBaseUrl();
$cloudSharedUrl = $cloudEndpointProvider->getCloudUrl();
$cloudKeySource = $cloudKeySourceSettings->getSource();
$isEnabled = $availabilityService->isEnabled();
$isOpenAppInIframeEnabled = $openAppSettings->isOpenInIframeEnabled();
$isRegistered = $registrationService->isRegistered();
$isCloudSharedConfigured = $registrationService->isCloudSharedConfigured();
$isCloudSharedAvailable = $registrationService->isCloudSharedAvailable();
$cloudSharedKeyFetchedAt = $service(CloudSharedKeyStore::class)->getFetchedAt();
$isCloudSharedKeyStored = $service(CloudSharedKeyStore::class)->get() !== '';
$pairings = $registrationService->listPairings();
$ttlHint = $pairingSettings->getMaxTtlSeconds();

// Known addresses offered for the active connection field: pairings, the cloud key source and the known
// Vibecode servers. The field itself stays hand-editable, the list only fills it.
$knownEndpoints = [];
$addKnownEndpoint = static function (string $url, string $phraseId) use (&$knownEndpoints): void {
	$url = rtrim(trim($url), '/');
	if ($url === '' || isset($knownEndpoints[$url]))
	{
		return;
	}

	$knownEndpoints[$url] = Loc::getMessage($phraseId, ['#URL#' => $url]);
};

foreach ($pairings as $pairingHint)
{
	$hintUrl = rtrim(trim($pairingHint->endpointUrl), '/');
	if ($hintUrl === '' || isset($knownEndpoints[$hintUrl]))
	{
		continue;
	}

	$knownEndpoints[$hintUrl] = $pairingHint->iss === ''
		? $hintUrl
		: Loc::getMessage('VIBECODECONNECTOR_OPT_ACTIVE_CONNECTION_KNOWN_PAIRING', [
			'#URL#' => $hintUrl,
			'#ISS#' => $pairingHint->iss,
		]);
}

if (IsModuleInstalled('bitrix24'))
{
	$addKnownEndpoint($cloudSharedUrl, 'VIBECODECONNECTOR_OPT_ACTIVE_CONNECTION_KNOWN_CLOUD');
}

foreach ($baseEndpointProvider->getAvailableUrls() as $availableUrl)
{
	$addKnownEndpoint($availableUrl, 'VIBECODECONNECTOR_OPT_ACTIVE_CONNECTION_KNOWN_DEFAULT');
}
$showDiagnosticLinks = Loader::includeModule('rest');

$registerUrlDefault = (string)$request->getPost('register_endpoint_url');
if ($registerUrlDefault === '')
{
	$registerUrlDefault = $baseEndpointProvider->getDefaultUrl();
}

$eventLogUrl = static fn (string $auditTypeId): string => '/bitrix/admin/event_log.php'
	. '?lang=' . urlencode(LANGUAGE_ID)
	. '&set_filter=Y'
	. '&find_module_id=' . urlencode('vibecodeconnector')
	. '&find_audit_type_id=' . urlencode($auditTypeId);

$incomingLogUrl = $eventLogUrl(IncomingJwtLog::AUDIT_TYPE_ID);
$cloudSharedKeyLogUrl = $eventLogUrl(CloudSharedKeyLog::AUDIT_TYPE_ID);
?>
<form id="vbcc-options-form" method="post" action="<?= $APPLICATION->GetCurPage() ?>?mid=<?= urlencode($module_id) ?>&amp;lang=<?= LANGUAGE_ID ?>">
<?= bitrix_sessid_post() ?>
<input type="hidden" name="iss" id="vbcc-iss-target" value="">

<?php $tabControl->Begin(); ?>
	<?php /* =================== TAB: Pairings ===================== */ ?>
	<?php $tabControl->BeginNextTab(); ?>

	<?php if ($registrationSuccess): ?>
	<tr><td colspan="2"><?CAdminMessage::ShowMessage(['TYPE' => 'OK', 'MESSAGE' => Loc::getMessage('VIBECODECONNECTOR_OPT_REGISTRATION_SUCCESS')])?></td></tr>
	<?php endif; ?>
	<?php if ($unregisterSuccess): ?>
	<tr><td colspan="2"><?CAdminMessage::ShowMessage(['TYPE' => 'OK', 'MESSAGE' => Loc::getMessage('VIBECODECONNECTOR_OPT_UNREGISTRATION_SUCCESS')])?></td></tr>
	<?php endif; ?>
	<?php if ($refreshSuccess): ?>
	<tr><td colspan="2"><?CAdminMessage::ShowMessage(['TYPE' => 'OK', 'MESSAGE' => Loc::getMessage('VIBECODECONNECTOR_OPT_REFRESH_SUCCESS')])?></td></tr>
	<?php endif; ?>
	<?php if ($unregisterError !== null): ?>
	<tr><td colspan="2"><?CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => Loc::getMessage('VIBECODECONNECTOR_OPT_UNREGISTRATION_ERROR', ['#MESSAGE#' => htmlspecialcharsbx($unregisterError)])])?></td></tr>
	<?php endif; ?>
	<?php if ($refreshError !== null): ?>
	<tr><td colspan="2"><?CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => Loc::getMessage('VIBECODECONNECTOR_OPT_REFRESH_ERROR', ['#MESSAGE#' => htmlspecialcharsbx($refreshError)])])?></td></tr>
	<?php endif; ?>
	<?php if ($baseUrlError !== null): ?>
	<tr><td colspan="2"><?CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => Loc::getMessage('VIBECODECONNECTOR_OPT_ACTIVE_CONNECTION_ERROR', ['#MESSAGE#' => htmlspecialcharsbx($baseUrlError)])])?></td></tr>
	<?php endif; ?>
	<?php if ($cloudSharedRefreshSuccess): ?>
	<tr><td colspan="2"><?CAdminMessage::ShowMessage(['TYPE' => 'OK', 'MESSAGE' => Loc::getMessage('VIBECODECONNECTOR_OPT_CLOUD_SHARED_REFRESH_SUCCESS')])?></td></tr>
	<?php endif; ?>
	<?php if ($cloudSharedRefreshError !== null): ?>
	<tr><td colspan="2"><?CAdminMessage::ShowMessage(['TYPE' => 'ERROR', 'MESSAGE' => Loc::getMessage('VIBECODECONNECTOR_OPT_CLOUD_SHARED_REFRESH_ERROR', ['#MESSAGE#' => htmlspecialcharsbx($cloudSharedRefreshError)])])?></td></tr>
	<?php endif; ?>
	<?php if ($pairingSourceSwitched): ?>
	<tr><td colspan="2"><?CAdminMessage::ShowMessage(['TYPE' => 'OK', 'MESSAGE' => Loc::getMessage('VIBECODECONNECTOR_OPT_PAIRING_SOURCE_SWITCHED')])?></td></tr>
	<?php endif; ?>
	<tr>
		<td style="width: 30%;"><label for="vibecodeconnector_is_ready"><?= Loc::getMessage('VIBECODECONNECTOR_OPT_IS_READY') ?>:</label></td>
		<td style="width: 70%;">
			<input
				type="checkbox"
				id="vibecodeconnector_is_ready"
				name="is_ready"
				value="Y"
				<?= $isEnabled ? 'checked' : '' ?>
				<?= $moduleAccess >= 'W' ? '' : 'disabled' ?>
			>
			<label for="vibecodeconnector_is_ready"><?= Loc::getMessage('VIBECODECONNECTOR_OPT_IS_READY_HINT') ?></label>
		</td>
	</tr>
	<tr>
		<td style="width: 30%;">
			<label for="vibecodeconnector_open_app_in_iframe">
				<?= Loc::getMessage('VIBECODECONNECTOR_OPT_OPEN_APP_IN_IFRAME') ?>:
			</label>
		</td>
		<td style="width: 70%;">
			<input
				type="checkbox"
				id="vibecodeconnector_open_app_in_iframe"
				name="open_app_in_iframe"
				value="Y"
				<?= $isOpenAppInIframeEnabled ? 'checked' : '' ?>
				<?= $moduleAccess >= 'W' ? '' : 'disabled' ?>
			>
			<label for="vibecodeconnector_open_app_in_iframe">
				<?= Loc::getMessage('VIBECODECONNECTOR_OPT_OPEN_APP_IN_IFRAME_HINT') ?>
			</label>
		</td>
	</tr>
	<tr>
		<td style="width: 30%;"><label for="vibecodeconnector_permission_source"><?= Loc::getMessage('VIBECODECONNECTOR_OPT_PERMISSION_SOURCE') ?>:</label></td>
		<td style="width: 70%;">
			<input
				type="checkbox"
				id="vibecodeconnector_permission_source"
				name="permission_source"
				value="Y"
				<?= $permissionSourcePolicy->isVibecodeSource() ? 'checked' : '' ?>
				<?= $moduleAccess >= 'W' ? '' : 'disabled' ?>
			>
			<label for="vibecodeconnector_permission_source"><?= Loc::getMessage('VIBECODECONNECTOR_OPT_PERMISSION_SOURCE_HINT') ?></label>
		</td>
	</tr>
	<tr>
		<td><label for="vibecodeconnector_endpoint"><?= Loc::getMessage('VIBECODECONNECTOR_OPT_ACTIVE_CONNECTION') ?>:</label></td>
		<td>
			<input type="text" id="vibecodeconnector_endpoint" name="endpoint_base_url"
				value="<?= htmlspecialcharsbx($endpointBaseUrl) ?>"
				placeholder="<?= htmlspecialcharsbx($baseEndpointProvider->getDefaultUrl()) ?>"
				<?= $moduleAccess >= 'W' ? '' : 'disabled' ?>
				style="width:60%;"
			>
			<?php if ($knownEndpoints !== []): ?>
				<br>
				<label for="vibecodeconnector_endpoint_known"><?= Loc::getMessage('VIBECODECONNECTOR_OPT_ACTIVE_CONNECTION_KNOWN') ?>:</label>
				<select
					id="vibecodeconnector_endpoint_known"
					<?= $moduleAccess >= 'W' ? '' : 'disabled' ?>
					style="min-width: 40%;"
				>
					<?php foreach ($knownEndpoints as $knownUrl => $knownLabel): ?>
						<option
							value="<?= htmlspecialcharsbx((string)$knownUrl) ?>"
							<?= rtrim($endpointBaseUrl, '/') === (string)$knownUrl ? 'selected' : '' ?>
						>
							<?= htmlspecialcharsbx($knownLabel) ?>
						</option>
					<?php endforeach; ?>
				</select>
				<button type="button" id="vibecodeconnector_endpoint_known_apply"
					<?= $moduleAccess >= 'W' ? '' : 'disabled' ?>
					onclick="
						document.getElementById('vibecodeconnector_endpoint').value =
							document.getElementById('vibecodeconnector_endpoint_known').value;
						document.getElementById('vibecodeconnector_endpoint').focus();
					">
					<?= Loc::getMessage('VIBECODECONNECTOR_OPT_ACTIVE_CONNECTION_KNOWN_INSERT') ?>
				</button>
			<?php endif; ?>
			<br>
			<small><?= Loc::getMessage('VIBECODECONNECTOR_OPT_ACTIVE_CONNECTION_HINT') ?></small>
		</td>
	</tr>
	<tr>
		<td><label for="vibecodeconnector_ttl"><?= Loc::getMessage('VIBECODECONNECTOR_OPT_MAX_TTL') ?>:</label></td>
		<td>
			<input
				type="number"
				id="vibecodeconnector_ttl"
				name="pairing_max_ttl_seconds"
				size="10"
				min="<?= PairingSettings::MIN_TTL_SECONDS ?>"
				max="<?= PairingSettings::MAX_TTL_SECONDS ?>"
				value="<?= (int)$ttlHint ?>"
				<?= $moduleAccess >= 'W' ? '' : 'disabled' ?>
			>
			<div class="adm-info-message-text"><?= Loc::getMessage('VIBECODECONNECTOR_OPT_MAX_TTL_HINT') ?></div>
		</td>
	</tr>

	<?php if (IsModuleInstalled('bitrix24')) { ?>
	<tr class="heading">
		<td colspan="2">
			<b><?= Loc::getMessage('VIBECODECONNECTOR_OPT_CLOUD_SHARED_TITLE') ?></b>
		</td>
	</tr>
	<tr>
		<td width="20%">
			<label for="vibecodeconnector_cloud_shared_url"><?= Loc::getMessage('VIBECODECONNECTOR_OPT_CLOUD_SHARED_URL') ?>:</label>
		</td>
		<td>
			<input type="text" id="vibecodeconnector_cloud_shared_url" name="cloud_shared_endpoint_url"
				value="<?= htmlspecialcharsbx($cloudSharedUrl) ?>"
				placeholder="<?= htmlspecialcharsbx($baseEndpointProvider->getDefaultUrl()) ?>"
				<?= $moduleAccess >= 'W' ? '' : 'disabled' ?>
				style="width:60%;"
				readonly="readonly"
			>
			<button type="button" id="vibecodeconnector_cloud_shared_url_edit_btn"
				<?= $moduleAccess >= 'W' ? '' : 'disabled' ?>
				onclick="
					document.getElementById('vibecodeconnector_cloud_shared_url').readOnly = false;
					document.getElementById('vibecodeconnector_cloud_shared_url').focus();
					this.style.display = 'none';
					document.getElementById('vibecodeconnector_cloud_shared_url_refresh_btn').style.display = 'none';
					document.getElementById('vibecodeconnector_cloud_shared_url_save_btn').style.display = '';
				">
				<?= Loc::getMessage('VIBECODECONNECTOR_OPT_CLOUD_SHARED_PUBLIC_KEY_EDIT') ?>
			</button>
			<button type="submit" id="vibecodeconnector_cloud_shared_url_refresh_btn" name="action" value="RefreshCloudSharedKey" <?= $moduleAccess >= 'W' ? '' : 'disabled' ?>>
				<?= Loc::getMessage('VIBECODECONNECTOR_OPT_CLOUD_SHARED_PUBLIC_KEY_REFRESH') ?>
			</button>
			<button type="submit" id="vibecodeconnector_cloud_shared_url_save_btn" name="action" value="SaveAndRefreshCloudSharedKey" style="display:none;" <?= $moduleAccess >= 'W' ? '' : 'disabled' ?>>
				<?= Loc::getMessage('VIBECODECONNECTOR_OPT_CLOUD_SHARED_PUBLIC_KEY_SAVE_AND_REFRESH') ?>
			</button>
		</td>
	</tr>
	<tr>
		<td width="20%">
			<?= Loc::getMessage('VIBECODECONNECTOR_OPT_CLOUD_SHARED_KEY_FETCHED_AT') ?>:
		</td>
		<td>
			<?php
			// The date is stamped only when the portal fetches the key itself; a key delivered by
			// an update lands in the option directly and has no mark.
			echo match (true) {
				$cloudSharedKeyFetchedAt > 0 => Loc::getMessage('VIBECODECONNECTOR_OPT_CLOUD_SHARED_KEY_FETCHED', [
					'#DATE#' => htmlspecialcharsbx(ConvertTimeStamp($cloudSharedKeyFetchedAt, 'FULL')),
				]),
				$isCloudSharedKeyStored => Loc::getMessage('VIBECODECONNECTOR_OPT_CLOUD_SHARED_KEY_DELIVERED'),
				default => Loc::getMessage('VIBECODECONNECTOR_OPT_CLOUD_SHARED_KEY_NOT_FETCHED'),
			};
			?>
		</td>
	</tr>
	<?php if ($showPublicKeySource): ?>
	<tr>
		<td>
			<label for="vibecodeconnector_cloud_shared_key_source"><?= Loc::getMessage('VIBECODECONNECTOR_OPT_CLOUD_SHARED_SOURCE') ?>:</label>
		</td>
		<td>
			<select
				id="vibecodeconnector_cloud_shared_key_source"
				name="cloud_shared_key_source"
				<?= $moduleAccess >= 'W' ? '' : 'disabled' ?>
				onchange="
					document.getElementById('vibecodeconnector_cloud_shared_url_edit_btn').style.display = 'none';
					document.getElementById('vibecodeconnector_cloud_shared_url_refresh_btn').style.display = 'none';
					document.getElementById('vibecodeconnector_cloud_shared_url_save_btn').style.display = '';
				"
			>
				<option value="<?= PublicKeySource::STATIC->value ?>" <?= $cloudKeySource === PublicKeySource::STATIC ? 'selected' : '' ?>>
					<?= Loc::getMessage('VIBECODECONNECTOR_OPT_KEY_SOURCE_STATIC') ?>
				</option>
				<option value="<?= PublicKeySource::MICROSERVICE->value ?>" <?= $cloudKeySource === PublicKeySource::MICROSERVICE ? 'selected' : '' ?>>
					<?= Loc::getMessage('VIBECODECONNECTOR_OPT_KEY_SOURCE_MICROSERVICE') ?>
				</option>
			</select>
		</td>
	</tr>
	<tr>
		<td colspan="2">
			<?=BeginNote()?><?= Loc::getMessage('VIBECODECONNECTOR_OPT_KEY_SOURCE_HINT') ?><?=EndNote();?>
		</td>
	</tr>
	<?php endif;?>
	<tr>
		<td colspan="2">
			<?php
			$cloudSharedHint = match (true) {
				!$isCloudSharedAvailable => 'VIBECODECONNECTOR_OPT_CLOUD_SHARED_NOT_APPLICABLE_HINT',
				$isCloudSharedConfigured => 'VIBECODECONNECTOR_OPT_CLOUD_SHARED_ACTIVE_HINT',
				default => 'VIBECODECONNECTOR_OPT_CLOUD_SHARED_NOT_CONFIGURED_HINT',
			};
			?>
			<?=BeginNote()?><?= Loc::getMessage($cloudSharedHint) ?>
				<br><br>
				<?= Loc::getMessage('VIBECODECONNECTOR_OPT_CLOUD_SHARED_AVAILABLE_SERVERS') ?>
				<?php foreach ($baseEndpointProvider->getAvailableUrls() as $availableUrl): ?>
					<br><?= htmlspecialcharsbx($availableUrl) ?>
				<?php endforeach; ?>
			<?=EndNote();?>
		</td>
	</tr>
	<?php } ?>
	<tr class="heading">
		<td colspan="2">
			<?= Loc::getMessage('VIBECODECONNECTOR_OPT_PAIRINGS_TITLE') ?>
		</td>
	</tr>
	<?php if (!$isRegistered): ?>
	<tr>
		<td colspan="2">
			<?=BeginNote()?><?= Loc::getMessage('VIBECODECONNECTOR_OPT_PAIRINGS_EMPTY')?><?=EndNote();
		?></td>
	</tr>
	<?php endif; ?>
	<?php if ($isRegistered): ?>
	<tr>
		<td colspan="2">
<?php
$sTableID = 'tbl_vbcc_pairings';
$oSort = new CAdminSorting($sTableID, 'iss', 'asc');
$lAdmin = new CAdminList($sTableID, $oSort);

$lAdmin->AddHeaders([
	['id' => 'iss', 'content' => Loc::getMessage('VIBECODECONNECTOR_OPT_PAIRING_COL_ISS'), 'default' => true],
	['id' => 'portal_id', 'content' => Loc::getMessage('VIBECODECONNECTOR_OPT_PAIRING_COL_PORTAL_ID'), 'default' => true],
	['id' => 'endpoint', 'content' => Loc::getMessage('VIBECODECONNECTOR_OPT_PAIRING_COL_ENDPOINT'), 'default' => true],
	['id' => 'fetched_at', 'content' => Loc::getMessage('VIBECODECONNECTOR_OPT_PAIRING_COL_FETCHED_AT'), 'default' => true],
	['id' => 'expires_at', 'content' => Loc::getMessage('VIBECODECONNECTOR_OPT_PAIRING_COL_EXPIRES_AT'), 'default' => true],
	['id' => 'key_source', 'content' => Loc::getMessage('VIBECODECONNECTOR_OPT_PAIRING_COL_KEY_SOURCE'), 'default' => true],
]);

$keySourceLabels = [
	PublicKeySource::STATIC->value => Loc::getMessage('VIBECODECONNECTOR_OPT_KEY_SOURCE_STATIC'),
	PublicKeySource::MICROSERVICE->value => Loc::getMessage('VIBECODECONNECTOR_OPT_KEY_SOURCE_MICROSERVICE'),
];

foreach ($pairings as $pairing)
{
	$expiresCell = htmlspecialcharsbx(date('Y-m-d H:i:s', $pairing->expiresAt));
	if ($pairing->isExpired())
	{
		$expiresCell .= ' <span style="color:#c00;font-weight:bold;">'
			. htmlspecialcharsbx(Loc::getMessage('VIBECODECONNECTOR_OPT_PAIRING_EXPIRED'))
			. '</span>';
	}

	$row = $lAdmin->AddRow($pairing->iss, [
		'iss' => htmlspecialcharsbx($pairing->iss),
		'portal_id' => htmlspecialcharsbx($pairing->portalId),
		'endpoint' => htmlspecialcharsbx($pairing->endpointUrl),
		'fetched_at' => htmlspecialcharsbx(date('Y-m-d H:i:s', $pairing->fetchedAt)),
		'expires_at' => $expiresCell,
		'key_source' => htmlspecialcharsbx($keySourceLabels[$pairing->keySource->value] ?? $pairing->keySource->value),
	]);

	if ($moduleAccess >= 'W')
	{
		$issJs = json_encode(
			$pairing->iss,
			JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT,
		);
		$actions = [
			[
				'ICON' => 'refresh',
				'TEXT' => Loc::getMessage('VIBECODECONNECTOR_OPT_PAIRING_BUTTON_REFRESH'),
				'DEFAULT' => true,
				'ACTION' => "vbccPairingSubmit({$issJs}, 'RefreshIss');",
			],
		];

		if ($showPublicKeySource && $pairing->keySource !== PublicKeySource::STATIC)
		{
			$actions[] = [
				'ICON' => 'edit',
				'TEXT' => Loc::getMessage('VIBECODECONNECTOR_OPT_PAIRING_BUTTON_SWITCH_STATIC'),
				'ACTION' => "vbccPairingSwitchSource({$issJs}, '" . PublicKeySource::STATIC->value . "');",
			];
		}
		if ($showPublicKeySource && $pairing->keySource !== PublicKeySource::MICROSERVICE)
		{
			$actions[] = [
				'ICON' => 'edit',
				'TEXT' => Loc::getMessage('VIBECODECONNECTOR_OPT_PAIRING_BUTTON_SWITCH_MICROSERVICE'),
				'ACTION' => "vbccPairingSwitchSource({$issJs}, '" . PublicKeySource::MICROSERVICE->value . "');",
			];
		}

		$actions[] = [
			'ICON' => 'delete',
			'TEXT' => Loc::getMessage('VIBECODECONNECTOR_OPT_PAIRING_BUTTON_UNREGISTER'),
			'ACTION' => "vbccPairingSubmit({$issJs}, 'UnregisterIss');",
		];

		$row->AddActions($actions);
	}
}

$lAdmin->CheckListMode();
$lAdmin->DisplayList();
?>
<script>
function vbccPairingSubmit(iss, action)
{
	var form = document.getElementById('vbcc-options-form');
	document.getElementById('vbcc-iss-target').value = iss;
	var actionField = document.createElement('input');
	actionField.type = 'hidden';
	actionField.name = 'action';
	actionField.value = action;
	form.appendChild(actionField);
	form.submit();
}
function vbccPairingSwitchSource(iss, source)
{
	var form = document.getElementById('vbcc-options-form');
	document.getElementById('vbcc-iss-target').value = iss;

	var sourceField = document.createElement('input');
	sourceField.type = 'hidden';
	sourceField.name = 'key_source';
	sourceField.value = source;
	form.appendChild(sourceField);

	var actionField = document.createElement('input');
	actionField.type = 'hidden';
	actionField.name = 'action';
	actionField.value = 'SwitchPairingKeySource';
	form.appendChild(actionField);

	form.submit();
}
</script>
		</td>
	</tr>
	<tr class="heading">
		<td colspan="2">
			<b><?= Loc::getMessage('VIBECODECONNECTOR_OPT_PAIRINGS_TITLE_NEW') ?></b>
		</td>
	</tr>
	<?php endif; ?>
	<?php if ($registrationError !== null):
		$errorMessageKey = match ($registrationError['code']) {
			'SIGNATURE_INVALID', 'LICENSE_REJECTED' => 'VIBECODECONNECTOR_OPT_REGISTRATION_ERROR_LICENSE',
			'UPSTREAM_UNAVAILABLE' => 'VIBECODECONNECTOR_OPT_REGISTRATION_ERROR_UPSTREAM',
			'MISSING_REQUIRED_FIELD', 'MALFORMED_REQUEST', 'PARAMS_INVALID' => 'VIBECODECONNECTOR_OPT_REGISTRATION_ERROR_PROTOCOL',
			'UNKNOWN_ACTION' => 'VIBECODECONNECTOR_OPT_REGISTRATION_ERROR_INCOMPATIBLE',
			'INTERNAL_ERROR' => 'VIBECODECONNECTOR_OPT_REGISTRATION_ERROR_INTERNAL',
			'INVALID_RESPONSE' => 'VIBECODECONNECTOR_OPT_REGISTRATION_ERROR_INVALID_RESPONSE',
			'URL_EMPTY', 'URL_MALFORMED', 'URL_SCHEME_NOT_ALLOWED', 'URL_HOST_MISSING' => 'VIBECODECONNECTOR_OPT_REGISTRATION_ERROR_URL',
			default => 'VIBECODECONNECTOR_OPT_REGISTRATION_ERROR',
		};
		?>
		<tr>
			<td colspan="2">
				<?CAdminMessage::ShowMessage([
					'TYPE' => 'ERROR',
					'MESSAGE' => Loc::getMessage($errorMessageKey, ['#MESSAGE#' => htmlspecialcharsbx($registrationError['message'])])
				])?>
			</td>
		</tr>
	<?php endif; ?>
	<tr>
		<td>
			<label for="vibecodeconnector_register_url">
				<?= Loc::getMessage('VIBECODECONNECTOR_OPT_PAIRINGS_ADD_TITLE') ?>:
			</label>
		</td>
		<td>
			<input
				type="text"
				id="vibecodeconnector_register_url"
				name="register_endpoint_url"
				value="<?= htmlspecialcharsbx($registerUrlDefault) ?>"
				placeholder="<?= htmlspecialcharsbx($baseEndpointProvider->getDefaultUrl()) ?>"
				<?= $moduleAccess >= 'W' ? '' : 'disabled' ?>
				style="width: 60%"
			>
			<button type="submit" name="action" value="Register" <?= $moduleAccess >= 'W' ? '' : 'disabled' ?>>
				<?= Loc::getMessage('VIBECODECONNECTOR_OPT_REGISTRATION_BUTTON') ?>
			</button>
		</td>
	</tr>
	<tr>
		<td colspan="2">
			<?=BeginNote()?><?= Loc::getMessage('VIBECODECONNECTOR_OPT_PAIRINGS_DESC') ?><?=EndNote();?>
		</td>
	</tr>
	<?php $tabControl->BeginNextTab(); ?>

	<?php if ($showDiagnosticLinks): ?>
	<tr>
		<td><?= Loc::getMessage('VIBECODECONNECTOR_OPT_DIAGNOSTIC_TOOLS') ?>:</td>
		<td>
			<a href="/bitrix/admin/vibecodeconnector_diagnostic.php?lang=<?= LANGUAGE_ID ?>">
				<?= Loc::getMessage('VIBECODECONNECTOR_OPT_DIAGNOSTIC_LINK') ?>
			</a>
			&nbsp;|&nbsp;
			<a href="/bitrix/admin/vibecodeconnector_developer_keys.php?lang=<?= LANGUAGE_ID ?>">
				<?= Loc::getMessage('VIBECODECONNECTOR_OPT_DEVELOPER_KEYS_LINK') ?>
			</a>
			&nbsp;|&nbsp;
			<a href="<?= htmlspecialcharsbx($incomingLogUrl) ?>" target="_blank">
				<?= Loc::getMessage('VIBECODECONNECTOR_OPT_INCOMING_LOG_LINK') ?>
			</a>
			&nbsp;|&nbsp;
			<a href="<?= htmlspecialcharsbx($cloudSharedKeyLogUrl) ?>" target="_blank">
				<?= Loc::getMessage('VIBECODECONNECTOR_OPT_CLOUD_SHARED_KEY_LOG_LINK') ?>
			</a>
		</td>
	</tr>
	<?php endif; ?>

	<tr>
		<td><?= Loc::getMessage('VIBECODECONNECTOR_OPT_CATALOG_PAGE') ?>:</td>
		<td>
			<a href="/bitrix/admin/vibecodeconnector_im_button.php?lang=<?= LANGUAGE_ID ?>">
				<?= Loc::getMessage('VIBECODECONNECTOR_OPT_CATALOG_PAGE_LINK') ?>
			</a>
		</td>
	</tr>

	<?php $tabControl->BeginNextTab(); ?>
	<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/bitrix/modules/main/admin/group_rights.php'; ?>
	<?php $tabControl->Buttons(); ?>

	<input
		type="submit"
		name="justButton"
		value="<?=Loc::getMessage('MAIN_SAVE')?>"
		class="adm-btn-save"
		onclick="document.getElementById('action_save').name = 'action';"
		<?= $moduleAccess >= 'W' ? '' : 'disabled' ?>
	>
	<input id="action_save" type="hidden" name="NotAnAction" value="Update" />
	<?php $tabControl->End(); ?>
</form>
