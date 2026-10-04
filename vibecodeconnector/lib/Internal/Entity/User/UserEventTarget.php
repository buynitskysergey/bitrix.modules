<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Entity\User;

final readonly class UserEventTarget
{
	public function __construct(
		public string $iss,
		public string $endpointUrl,
	) {
	}
}
