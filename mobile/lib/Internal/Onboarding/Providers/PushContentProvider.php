<?php

namespace Bitrix\Mobile\Internal\Onboarding\Providers;

use Bitrix\Mobile\Internal\Onboarding\Dto\PushContent;
use Bitrix\Mobile\Internal\Onboarding\Region\RegionConfig;

class PushContentProvider
{
	private RegionConfig $regionConfig;

	public function __construct(RegionConfig $regionConfig)
	{
		$this->regionConfig = $regionConfig;
	}

	public function getContentForDay(int $day): ?PushContent
	{
		$dayConfig = $this->regionConfig->getDay($day);

		if ($dayConfig === null)
		{
			return null;
		}

		return new PushContent(
			title: $dayConfig->getTitle(),
			text: $dayConfig->getText(),
			type: $dayConfig->getType(),
		);
	}

	public function getTitleForDay(int $day): string
	{
		$content = $this->getContentForDay($day);

		return $content?->title ?? '';
	}

	public function getBodyForDay(int $day): string
	{
		$content = $this->getContentForDay($day);

		return $content?->text ?? '';
	}
}
