<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Entity;

use Bitrix\BizprocDesigner\Internal\Entity\DocumentDescription;
use Bitrix\Main\Type\DateTime;

final class WorkflowTemplateIdentifier
{
	/**
	 * Name and modification date come from the row of the template, so whoever read that row to build the
	 * identifier carries them along. They stay empty when the identifier is built from a draft alone.
	 */
	public function __construct(
		public readonly DocumentDescription $documentDescription,
		public readonly ?int $templateId,
		public readonly ?int $draftId = null,
		public readonly ?string $name = null,
		public readonly ?DateTime $modified = null,
	) {}

	/** The same template, pointed at another draft of it - the one a write has just produced. */
	public function withDraftId(int $draftId): self
	{
		return new self($this->documentDescription, $this->templateId, $draftId, $this->name, $this->modified);
	}
}
