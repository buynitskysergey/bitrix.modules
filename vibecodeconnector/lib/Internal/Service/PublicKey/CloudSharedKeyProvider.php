<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\PublicKey;

/**
 * Source of the cloud-shared public key: fetches it and persists it.
 */
interface CloudSharedKeyProvider
{
	/**
	 * @return string PEM that is now stored
	 */
	public function refresh(): string;
}
