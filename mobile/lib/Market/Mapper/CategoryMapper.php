<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Market\Mapper;

use Bitrix\Mobile\Internal\Integration\Market\MarketService;
use Bitrix\Mobile\Market\Dto\Category;
use Bitrix\Mobile\Market\Navigation;

final class CategoryMapper
{
	public function __construct(
		private readonly MarketService $marketService,
	)
	{
	}

	public function map(array $category): Category
	{
		$code = (string)($category['CODE'] ?? '');
		$description = trim((string)($category['DESCRIPTION'] ?? ''));

		return new Category(
			id: $code,
			code: $code,
			title: (string)($category['NAME'] ?? ''),
			description: $description,
			appsCount: (int)($category['CNT'] ?? 0),
			color: (string)($category['COLOR'] ?? ''),
			url: ($code !== '' ? $this->marketService->getCategoryPageUrl($code, Navigation::HOME_FROM) : ''),
		);
	}

	public function mapCollection(array $categories): array
	{
		$result = [];

		foreach ($categories as $category)
		{
			if (is_array($category))
			{
				$result[] = $this->map($category);
			}
		}

		return $result;
	}
}
