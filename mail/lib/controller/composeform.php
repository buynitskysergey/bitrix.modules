<?php

declare(strict_types=1);

namespace Bitrix\Mail\Controller;

use Bitrix\Calendar;
use Bitrix\Intranet;
use Bitrix\Mail\Service\Compose\SignatureListProvider;
use Bitrix\Main\Loader;

/**
 * Actions the redesigned compose form needs once it is already on the screen.
 * Scope: api, available as mail.api.composeform.*
 *
 * Both actions work in the context of the current user and take no user identifier: there is no way
 * to reach somebody else's signatures or sharing link through them.
 *
 * Contract:
 *   getSignatures          -> {bySender: object<string, item[]>, choices: object<string, string>}
 *   getCalendarSharingLink -> {isSharingFeatureEnabled: bool, sharingUrl?: string}
 *
 * The actions of the shared main.mail.form component stay where they are: the old form keeps calling
 * them.
 */
class ComposeForm extends Base
{
	/**
	 * Signatures of the current user, in the very shape the form starts with (DTO-01, key
	 * 'signatures', minus the settings path the initial data carries). Called after the signature
	 * settings slider is closed.
	 *
	 * Both maps are handed over as objects: an empty PHP map would otherwise serialise to [] and the
	 * type of the field would depend on whether there is data; the template of the form casts them
	 * the same way.
	 *
	 * @return array{bySender: \stdClass, choices: \stdClass}
	 */
	public function getSignaturesAction(): array
	{
		$signatures = (new SignatureListProvider())->getSignatures((int)$this->getCurrentUser()->getId());

		return [
			'bySender' => (object)$signatures['bySender'],
			'choices' => (object)$signatures['choices'],
		];
	}

	/**
	 * Short link to the calendar slots of the current user.
	 *
	 * The CRM deal branch of the shared component is not reproduced: the mail compose form has no deal
	 * to share slots for.
	 *
	 * @return array{isSharingFeatureEnabled: bool, sharingUrl?: string}
	 */
	public function getCalendarSharingLinkAction(): array
	{
		if (!$this->isCalendarSharingAvailable())
		{
			return ['isSharingFeatureEnabled' => false];
		}

		$sharing = new Calendar\Sharing\Sharing((int)$this->getCurrentUser()->getId());
		$response = ['isSharingFeatureEnabled' => $sharing->isEnabled()];

		$sharingUrl = (string)$sharing->getActiveLinkShortUrl();
		if ($sharingUrl !== '')
		{
			$response['sharingUrl'] = $sharingUrl;
		}

		return $response;
	}

	/**
	 * A missing calendar module and a user outside the intranet both mean the feature is off, not that
	 * the request has failed.
	 */
	protected function isCalendarSharingAvailable(): bool
	{
		if (!Loader::includeModule('calendar'))
		{
			return false;
		}

		return !Loader::includeModule('intranet') || Intranet\Util::isIntranetUser();
	}
}
