<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Item\File\Value;

final class FileUploadInput
{
	public function __construct(
		public readonly string $name,
		public readonly ?string $data,
		public readonly ?string $url,
		public readonly string $path,
	)
	{
	}
}
