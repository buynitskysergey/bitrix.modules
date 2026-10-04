<?php

namespace Bitrix\Bizproc\Api\Response\WorkflowTemplateHistoryService;

use Bitrix\Bizproc\Api\Data\WorkflowTemplateHistoryService\TemplateVersion;
use Bitrix\Bizproc\Result;

final class GetVersionResponse extends Result
{
	public function getVersion(): ?TemplateVersion
	{
		return $this->data['version'] ?? null;
	}

	public function setVersion(TemplateVersion $version): self
	{
		$this->data['version'] = $version;

		return $this;
	}

	public function getTemplateData(): array
	{
		return $this->data['templateData'] ?? [];
	}

	public function setTemplateData(array $templateData): self
	{
		$this->data['templateData'] = $templateData;

		return $this;
	}
}
