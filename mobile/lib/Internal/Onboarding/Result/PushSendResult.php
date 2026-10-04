<?php

namespace Bitrix\Mobile\Internal\Onboarding\Result;

use Bitrix\Main\Result;

class PushSendResult extends Result
{
	public function setPushType(?string $pushType): self
	{
		$this->data['pushType'] = $pushType;

		return $this;
	}

	public function getPushType(): ?string
	{
		return $this->data['pushType'] ?? null;
	}

	public function wasSent(): bool
	{
		return $this->isSuccess();
	}

	public function getFailureReason(): ?string
	{
		return $this->getError()?->getMessage();
	}
}
