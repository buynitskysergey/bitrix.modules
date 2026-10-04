<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\SourceGeneration;

/**
 * The single place that builds the b_mail_message_uid row id from physical coordinates.
 * Direct copies of the formula are forbidden: the id must be reproducible from one spot.
 */
final class UidIdentity
{
	// Version of the generation-prefixed formula, becomes part of the hashed payload
	private const GENERATION_FORMULA_VERSION = 1;

	public static function build(int $generationId, string $dirPath, int|string $uidValidity, int|string $uid): string
	{
		if ($generationId <= 0)
		{
			// Historical G1 formula: old uid ids are never rewritten, keep it byte-identical
			return md5(sprintf('%s:%u:%u', $dirPath, $uidValidity, $uid));
		}

		return md5(sprintf(
			'v%u:%u:%s:%u:%u',
			self::GENERATION_FORMULA_VERSION,
			$generationId,
			$dirPath,
			$uidValidity,
			$uid,
		));
	}
}
