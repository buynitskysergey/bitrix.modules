<?php

namespace Bitrix\Bizproc\Api\Response\WorkflowTemplateHistoryService;

use Bitrix\Bizproc\Result;

final class RestoreVersionResponse extends Result
{
	/**
	 * @return int|null null means the template is left without drafts and the editor returns to the
	 * published configuration
	 */
	public function getDraftId(): ?int
	{
		return $this->data['draftId'] ?? null;
	}

	public function setDraftId(?int $draftId): self
	{
		$this->data['draftId'] = $draftId;

		return $this;
	}
}
