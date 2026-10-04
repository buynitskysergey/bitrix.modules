<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper\Config;

use Bitrix\Mail\Helper\MailAccess;
use Bitrix\Main\Config\Option;
use Bitrix\Main\Loader;
use Bitrix\Main\ModuleManager;

class Feature
{
	private const AUTO_CLASSIFY_INCOMING_OPTION = 'auto_classify_incoming';
	private const MOBILE_SIGNATURES_OPTION = 'signatures_enabled';

	public static function isInternalDraftsAvailable(): bool
	{
		return Option::get('mail', 'internal_drafts_enabled', 'N') === 'Y';
	}

	public static function isInternalDraftsWebAvailable(): bool
	{
		return self::isInternalDraftsAvailable()
			&& InternalDraftCompatibility::isMainVersionSupported(ModuleManager::getVersion('main'))
		;
	}

	public static function isMailboxGridAvailable(): bool
	{
		return true;
	}

	public static function isMailboxGridBulkActionsAvailable(): bool
	{
		return Option::get('mail', 'mailbox_grid_bulk_actions_enabled', 'N') === 'Y';
	}

	public static function isPasswordlessConnectAvailable(): bool
	{
		return true;
	}

	public static function isMailboxConnectionRequestAvailable(): bool
	{
		return Loader::includeModule('im');
	}

	public static function isMailboxConfigRedesignAvailable(): bool
	{
		return true;
	}

	public static function isMailboxOwnerChangeAvailable(): bool
	{
		return true;
	}

	public static function isMailListImprovementsAvailable(): bool
	{
		return Option::get('mail', 'enable_list_improvements', 'N') === 'Y';
	}

	public static function isCrmAvailable(): bool
	{
		return MailAccess::hasCurrentUserAdminAccess()
			|| Option::get('intranet', 'allow_external_mail_crm', 'Y', SITE_ID) === 'Y'
		;
	}

	public static function isUnlimitedMailSyncPeriodAvailable(): bool
	{
		return !ModuleManager::isModuleInstalled('bitrix24');
	}

	public static function isHistorySyncByDateSearchEnabled(): bool
	{
		return Option::get('mail', 'sync_history_by_date_search', 'Y') === 'Y';
	}

	public static function isAutoClassifyIncomingAvailable(): bool
	{
		return Option::get('mail', self::AUTO_CLASSIFY_INCOMING_OPTION, 'N') === 'Y';
	}

	public static function isFolderManualSortingAvailable(): bool
	{
		return Option::get('mail', 'folder_sorting_enabled', 'N') === 'Y';
	}

	public static function isLargeAttachmentDiskUploadAvailable(): bool
	{
		return Option::get('mail', 'large_attachment_disk_upload_enabled', 'N') === 'Y';
	}

	public static function isComposeRedesignAvailable(): bool
	{
		return Option::get('mail', 'compose_redesign_enabled', 'N') === 'Y';
	}

	public static function isComposeUnfinishedElementsAvailable(): bool
	{
		return self::isComposeRedesignAvailable()
			&& Option::get('mail', 'compose_unfinished_elements_enabled', 'N') === 'Y';
	}

	public static function isSignatureMacrosAvailable(): bool
	{
		return Option::get('mail', 'signature_macros_enabled', 'N') === 'Y';
	}

	public static function isSharedSignaturePermissionAvailable(): bool
	{
		return Option::get('mail', 'shared_signature_permission_enabled', 'N') === 'Y';
	}

	public static function isComposeTemplatesAvailable(): bool
	{
		return Option::get('mail', 'compose_templates_enabled', 'Y') === 'Y';
	}

	/**
	 * Owned by the mailmobile feature flag; read as a plain option so that mail keeps no
	 * dependency on the mobile modules.
	 */
	public static function isMobileSignaturesAvailable(): bool
	{
		return Option::get('mailmobile', self::MOBILE_SIGNATURES_OPTION, 'N') === 'Y';
	}
}
