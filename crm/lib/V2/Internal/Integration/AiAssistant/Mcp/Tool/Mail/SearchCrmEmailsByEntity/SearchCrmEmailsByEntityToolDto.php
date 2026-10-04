<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Mail\SearchCrmEmailsByEntity;

use Bitrix\Crm\Dto\Validator\IntegerField;
use Bitrix\Crm\Dto\Validator\RequiredField;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;

final class SearchCrmEmailsByEntityToolDto extends AbstractToolDto
{
	public ?int $entityTypeId = null;
	public ?int $entityId = null;
	public ?int $direction = null;
	public ?int $limit = null;
	public ?int $offset = null;

	protected function getValidators(array $fields): array
	{
		$validators = [
			new RequiredField($this, 'entityTypeId'),
			new IntegerField($this, 'entityTypeId'),

			new RequiredField($this, 'entityId'),
			new IntegerField($this, 'entityId'),
		];

		foreach (['direction', 'limit', 'offset'] as $field)
		{
			if (array_key_exists($field, $fields) && $fields[$field] !== null)
			{
				$validators[] = new IntegerField($this, $field);
			}
		}

		return $validators;
	}
}
