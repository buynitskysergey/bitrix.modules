<?php

namespace Bitrix\Crm\Service\Timeline\AiReportDrawer\Presentation;

use Bitrix\Crm\Service\Timeline\AiReportDrawer\Dto\ActivityContext;

final class PresentationResolver
{
	/** @var ActivityPresentationBuilderInterface[] */
	private array $builders;

	public function __construct(ActivityPresentationBuilderInterface ...$builders)
	{
		$this->builders = $builders;
	}

	public function buildSubtitle(ActivityContext $context): ?array
	{
		$builder = $this->resolve($context);

		return $builder?->buildSubtitle($context);
	}

	public function buildInfoPopup(ActivityContext $context): ?array
	{
		$builder = $this->resolve($context);

		return $builder?->buildInfoPopup($context);
	}

	private function resolve(ActivityContext $context): ?ActivityPresentationBuilderInterface
	{
		foreach ($this->builders as $builder)
		{
			if ($builder->supports($context))
			{
				return $builder;
			}
		}

		return null;
	}
}
