<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Flipchart;

use Bitrix\Disk\Controller\Integration\Flipchart;
use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Main\Web\Uri;

/**
 * Single place that builds the board document download url.
 *
 * The url used to be assembled in three call sites; a missed one would stay a bypass, so the grant
 * is added here and only here. The legacy http downgrade is not shared: it belongs to the call sites
 * that had it, so every caller states its own transport policy.
 */
final class DocumentDownloadUrlService
{
	public static function shouldDowngradeToHttp(bool $callerOptedIn): bool
	{
		return $callerOptedIn && Configuration::isForceHttpForDocumentUrl();
	}

	public function build(DocumentSession $session, bool $applyLegacyHttpDowngrade): Uri
	{
		$uri = (new Flipchart())->getActionUri(
			'getDocument',
			[
				'sessionId' => $session->getExternalHash(),
				'userId' => $session->getUserId(),
				DocumentDownloadGrant::PARAMETER => DocumentDownloadGrant::issue($session),
			],
			true,
		);

		if (self::shouldDowngradeToHttp($applyLegacyHttpDowngrade))
		{
			$uri = $uri->withScheme('http');
		}

		return $uri;
	}
}
