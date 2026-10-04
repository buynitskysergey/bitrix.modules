<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Activity\Registry;

use Bitrix\Bizproc\Public\Activity\Interface\RelationFieldResolver;
use Bitrix\Main\Loader;

final class RelationFieldResolverRegistry
{
	/** @var array<string, RelationFieldResolver|null> */
	private array $resolversByModule = [];

	public function register(string $documentModule, RelationFieldResolver $resolver): void
	{
		$this->resolversByModule[mb_strtolower($documentModule)] = $resolver;
	}

	/**
	 * Resolves the relation-field resolver owned by the target document module.
	 *
	 * Design-time only: there is no live \CBPActivity, so the owner module is taken from the
	 * target documentType triplet. Returns null when the module owns no resolver (fail-open -
	 * the caller treats it as "no relation fields"). Applicability is decided by the caller via
	 * RelationFieldResolver::supports(); this registry never calls supports() itself.
	 */
	public function resolve(array $targetDocumentType): ?RelationFieldResolver
	{
		$module = mb_strtolower((string)($targetDocumentType[0] ?? ''));
		if ($module === '')
		{
			return null;
		}

		if (!array_key_exists($module, $this->resolversByModule))
		{
			$this->resolversByModule[$module] = $this->loadResolver($module);
		}

		return $this->resolversByModule[$module];
	}

	private function loadResolver(string $module): ?RelationFieldResolver
	{
		$className = sprintf(
			'Bitrix\\%s\\Integration\\BizProc\\NodeFilter\\RelationFieldResolver',
			ucfirst($module),
		);

		if (!Loader::includeModule($module) || !class_exists($className))
		{
			return null;
		}

		$instance = new $className();

		return $instance instanceof RelationFieldResolver ? $instance : null;
	}
}
