<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Market\Dto;

use Bitrix\Mobile\Dto\Dto;

final class Tab extends Dto
{
	public function __construct(
		public string $id = '',
		public string $title = '',
		public bool $active = false,
		public string $url = '',
		public array $payload = [],
	)
	{
		parent::__construct();
	}

	public function toArray(): array
	{
		$result = [
			'id' => $this->id,
			'title' => $this->title,
			'active' => $this->active,
		];

		if ($this->url !== '')
		{
			$result['url'] = $this->url;
		}

		if (!empty($this->payload))
		{
			$result['payload'] = $this->payload;
		}

		return $result;
	}
}
