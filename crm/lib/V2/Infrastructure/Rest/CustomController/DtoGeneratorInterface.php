<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\CustomController;

use Bitrix\Rest\V3\Schema\GeneratedDto;

interface DtoGeneratorInterface
{
	public function generate(): GeneratedDto;
}
