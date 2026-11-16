<?php

declare(strict_types=1);

namespace Bitrix\Note\Integration\BizProc\NodeFilter;

use Bitrix\Note\Internal\Integration\Bizproc\NodeFilter\FilterResultPropertyResolver as InternalResolver;

/**
 * The address bizproc releases older than 26.2000.0 look the resolver up by: they know only the
 * `Bitrix\<Module>\Integration\BizProc` convention, not `Internal`. Without it the knowledge base node
 * returns no selection result there. Newer releases try this address first as well.
 */
final class FilterResultPropertyResolver
	implements \Bitrix\Bizproc\Public\Activity\Interface\FilterResultPropertyResolver
{
	private readonly InternalResolver $resolver;

	public function __construct()
	{
		$this->resolver = new InternalResolver();
	}

	public function supports(\CBPActivity $activity): bool
	{
		return $this->resolver->supports($activity);
	}

	public function resolveProperties(\CBPActivity $activity): array
	{
		return $this->resolver->resolveProperties($activity);
	}
}
