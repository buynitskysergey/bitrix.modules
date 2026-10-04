<?php

namespace Bitrix\Bizproc\Api\Response\WorkflowTemplateHistoryService;

use Bitrix\Bizproc\Api\Data\WorkflowTemplateHistoryService\TemplateVersion;
use Bitrix\Bizproc\Result;

final class GetVersionsResponse extends Result
{
	/**
	 * @return TemplateVersion[]
	 */
	public function getVersions(): array
	{
		return $this->data['versions'] ?? [];
	}

	/**
	 * @param TemplateVersion[] $versions
	 */
	public function setVersions(array $versions): self
	{
		$this->data['versions'] = $versions;

		return $this;
	}
}
