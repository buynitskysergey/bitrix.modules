<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Public\Type\CustomTemplate;

use Bitrix\Main\Validation\Rule\Length;
use Bitrix\Main\Validation\Rule\NotEmpty;

final class CustomTemplate
{
	public const TITLE_MAX_LENGTH = 255;
	public const BODY_MAX_LENGTH = 4000;

	public function __construct(
		#[NotEmpty]
		#[Length(min: 1, max: self::TITLE_MAX_LENGTH)]
		public readonly string $title,
		#[NotEmpty]
		#[Length(min: 1, max: self::BODY_MAX_LENGTH)]
		public readonly string $body,
	) {}
}
