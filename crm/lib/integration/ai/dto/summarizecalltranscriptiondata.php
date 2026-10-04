<?php

namespace Bitrix\Crm\Integration\AI\Dto;

use Bitrix\Crm\Dto\Dto;

final class SummarizeCallTranscriptionData extends Dto
{
	public ?string $theme = null;
	public ?string $product = null;
	public ?string $intent = null;
}
