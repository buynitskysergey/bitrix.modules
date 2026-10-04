<?php

namespace Bitrix\Rest\V3\Realisation\Response;

use Bitrix\Rest\V3\Interaction\Response\Response;

class DownloadUrlResponse extends Response
{
	public function __construct(public string $downloadUrl)
	{
	}
}
