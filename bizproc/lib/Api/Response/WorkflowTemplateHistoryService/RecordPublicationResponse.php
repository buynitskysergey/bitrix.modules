<?php

namespace Bitrix\Bizproc\Api\Response\WorkflowTemplateHistoryService;

use Bitrix\Bizproc\Result;

final class RecordPublicationResponse extends Result
{
	public function getVersionNumber(): int
	{
		return $this->data['versionNumber'] ?? 0;
	}

	public function setVersionNumber(int $versionNumber): self
	{
		$this->data['versionNumber'] = $versionNumber;

		return $this;
	}
}
