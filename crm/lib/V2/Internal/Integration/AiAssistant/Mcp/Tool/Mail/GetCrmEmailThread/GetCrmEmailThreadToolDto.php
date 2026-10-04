<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Mail\GetCrmEmailThread;

use Bitrix\Crm\Dto\Validator\IntegerField;
use Bitrix\Crm\Dto\Validator\RequiredField;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;

final class GetCrmEmailThreadToolDto extends AbstractToolDto
{
	public ?int $activityId = null;

	protected function getValidators(array $fields): array
	{
		return [
			new RequiredField($this, 'activityId'),
			new IntegerField($this, 'activityId'),
		];
	}
}
