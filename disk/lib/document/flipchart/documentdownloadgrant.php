<?php

declare(strict_types=1);

namespace Bitrix\Disk\Document\Flipchart;

use Bitrix\Disk\Document\Models\DocumentSession;
use Bitrix\Main\Security\Sign\BadSignatureException;
use Bitrix\Main\Security\Sign\TimeSigner;
use Bitrix\Main\Web\Json;

/**
 * Signed grant for the initial download of a board document.
 *
 * The grant covers the download only: it does not shorten the board JWT lifetime. Purpose is bound
 * twice — as the signing salt and as a payload field — and the payload is bound to session, object
 * and version, so a grant issued for one board cannot be replayed for another.
 */
final class DocumentDownloadGrant
{
	public const PARAMETER = 'grant';
	public const PURPOSE = 'disk.flipchart.getDocument';
	public const TTL = '+15 minutes';

	public static function issue(DocumentSession $session): string
	{
		return self::issueFor(
			(string)$session->getExternalHash(),
			(int)$session->getObjectId(),
			$session->getVersionId(),
		);
	}

	public static function issueFor(string $sessionHash, int $objectId, ?int $versionId): string
	{
		$value = Json::encode([
			'p' => self::PURPOSE,
			's' => $sessionHash,
			'o' => $objectId,
			'v' => self::normalizeVersionId($versionId),
		]);

		return (new TimeSigner())->sign($value, self::TTL, self::PURPOSE);
	}

	/**
	 * Expired lifetime, foreign salt, broken signature, unparsable payload and a missing key are all
	 * the same rejection.
	 *
	 * @return array{p: string, s: string, o: int, v: int}|null
	 */
	public static function open(string $grant): ?array
	{
		if ($grant === '')
		{
			return null;
		}

		try
		{
			$value = (new TimeSigner())->unsign($grant, self::PURPOSE);
			$payload = Json::decode($value);
		}
		catch (BadSignatureException|\Throwable)
		{
			return null;
		}

		if (
			!is_array($payload)
			|| !isset($payload['p'], $payload['s'], $payload['o'], $payload['v'])
			|| !is_string($payload['p'])
			|| !is_string($payload['s'])
			|| !is_int($payload['o'])
			|| !is_int($payload['v'])
			|| $payload['p'] !== self::PURPOSE
		)
		{
			return null;
		}

		return $payload;
	}

	/**
	 * DocumentSession::getVersionId() is nullable, so the value is normalized the same way on issuing
	 * and on verification.
	 */
	public static function normalizeVersionId(?int $versionId): int
	{
		return $versionId ?? 0;
	}
}
