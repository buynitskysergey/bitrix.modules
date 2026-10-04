<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\Mail\ReplyToCrmEmail;

use Bitrix\Crm\Dto\Caster;
use Bitrix\Crm\Dto\Validator\IntegerField;
use Bitrix\Crm\Dto\Validator\NotEmptyField;
use Bitrix\Crm\Dto\Validator\RequiredField;
use Bitrix\Crm\Dto\Validator\ScalarCollectionField;
use Bitrix\Crm\Dto\Validator\StringField;
use Bitrix\Crm\Integration\Mail\RecipientLimitProvider;
use Bitrix\Crm\V2\Internal\Integration\AiAssistant\Mcp\Tool\AbstractToolDto;

final class ReplyToCrmEmailToolDto extends AbstractToolDto
{
	public ?int $activityId = null;
	public ?string $body = null;
	public ?array $cc = null;
	public ?array $bcc = null;
	public ?string $from = null;

	public function getCastByPropertyName(string $propertyName): ?Caster
	{
		return match ($propertyName)
		{
			'cc', 'bcc' => new Caster\CollectionCaster(new Caster\StringCaster()),
			default => null,
		};
	}

	protected function getValidators(array $fields): array
	{
		$recipientsLimit = RecipientLimitProvider::getTotal();

		return [
			new RequiredField($this, 'activityId'),
			new IntegerField($this, 'activityId'),

			new RequiredField($this, 'body'),
			new StringField($this, 'body'),
			new NotEmptyField($this, 'body'),

			new ScalarCollectionField($this, 'cc', maxCount: $recipientsLimit, onlyNotEmptyValues: true),
			new ScalarCollectionField($this, 'bcc', maxCount: $recipientsLimit, onlyNotEmptyValues: true),

			new StringField($this, 'from'),
		];
	}
}
