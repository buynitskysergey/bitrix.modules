<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Public\Command\CustomTemplate;

use Bitrix\Main\Result;
use Bitrix\MessageService\Public\Dto\CustomTemplate\CustomTemplateDetails;

final class CustomTemplateResult extends Result
{
	private ?CustomTemplateDetails $template = null;

	public function setTemplate(CustomTemplateDetails $template): self
	{
		$this->template = $template;

		return $this;
	}

	public function getTemplate(): ?CustomTemplateDetails
	{
		return $this->template;
	}
}
