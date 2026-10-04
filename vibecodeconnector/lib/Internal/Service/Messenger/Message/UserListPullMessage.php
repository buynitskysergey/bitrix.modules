<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Service\Messenger\Message;

use Bitrix\Main\Messenger\Entity\AbstractMessage;
use Bitrix\Main\UuidGenerator;

final class UserListPullMessage extends AbstractMessage
{
	public string $generationId;

	public function __construct(
		public string $pairingIss,
		public string $endpointUrl,
		public int $attemptCount = 0,
		public ?int $version = null,
		?string $generationId = null,
	) {
		$this->generationId = $generationId ?? UuidGenerator::generateV4();
	}
}
