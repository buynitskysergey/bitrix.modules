<?php

namespace Bitrix\Mobile\Internal\Onboarding\Result;

use Bitrix\Main\Result;

class ProcessResult extends Result
{
	public function setProcessed(int $processed): self
	{
		$this->data['processed'] = $processed;

		return $this;
	}

	public function getProcessed(): int
	{
		return $this->data['processed'] ?? 0;
	}

	public function setSent(int $sent): self
	{
		$this->data['sent'] = $sent;

		return $this;
	}

	public function getSent(): int
	{
		return $this->data['sent'] ?? 0;
	}

	public function setSkipped(int $skipped): self
	{
		$this->data['skipped'] = $skipped;

		return $this;
	}

	public function getSkipped(): int
	{
		return $this->data['skipped'] ?? 0;
	}
}
