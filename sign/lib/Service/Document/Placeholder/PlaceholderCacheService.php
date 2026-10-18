<?php

namespace Bitrix\Sign\Service\Document\Placeholder;

use Bitrix\Sign\Config;
use Bitrix\Sign\Util\MainCache;

class PlaceholderCacheService
{
	private const LEGACY_PLACEHOLDER_LIST_CACHE_KEY = 'sign_document_placeholder_data';
	private const VERIFIED_PLACEHOLDER_LIST_CACHE_KEY = 'sign_document_placeholder_data_verified';

	// own scope of the cache of the module: the lists are dropped as a whole, see the invalidation
	private const CACHE_SCOPE = 'placeholder';

	private readonly MainCache $cache;
	private readonly MainCache $rootCache;
	private readonly Config\Storage $config;

	/**
	 * Typed by the implementation and not by Contract\Util\Cache, because the contract carries no
	 * scope: this service owns one and drops it as a whole.
	 */
	public function __construct(
		MainCache $cache,
		?Config\Storage $config = null,
	)
	{
		$this->rootCache = $cache;
		$this->cache = $cache->withScope(self::CACHE_SCOPE);
		$this->config = $config ?? Config\Storage::instance();
	}

	public function getPlaceholderList(): mixed
	{
		return $this->cache->get($this->getPlaceholderListCacheKey());
	}

	public function setPlaceholderList(array $placeholderList): MainCache
	{
		return $this->cache->set($this->getPlaceholderListCacheKey(), $placeholderList);
	}

	public function getPlaceholderListByHcmLinkCompanyId(int $hcmLinkCompanyId): mixed
	{
		return $this->cache->get($this->getPlaceholderListCacheKeyByHcmLinkCompanyId($hcmLinkCompanyId));
	}

	public function setPlaceholderListByHcmLinkCompanyId(int $hcmLinkCompanyId, array $placeholderList): MainCache
	{
		return $this->cache->set($this->getPlaceholderListCacheKeyByHcmLinkCompanyId($hcmLinkCompanyId), $placeholderList);
	}

	/**
	 * Drops every cached fields list at once: the per-company lists of the external HR system carry
	 * the same items as the main list, but their keys hold company ids and cannot be enumerated.
	 * The reset reaches the own scope of the lists only, so the keys of the other consumers of the
	 * cache of the module survive it.
	 */
	public function invalidateDocumentPlaceholderListCache(): void
	{
		$this->cache->deleteAll();
		$this->deleteListsOfTheRootScope();
	}

	/**
	 * Lists the versions before the move wrote into the root of the cache of the module. Their
	 * per-company keys hold company ids, cannot be enumerated and wait for their ttl.
	 */
	private function deleteListsOfTheRootScope(): void
	{
		$this->rootCache->delete(self::LEGACY_PLACEHOLDER_LIST_CACHE_KEY);
		$this->rootCache->delete(self::VERIFIED_PLACEHOLDER_LIST_CACHE_KEY);
	}

	private function getPlaceholderListCacheKey(): string
	{
		return $this->config->isPlaceholderVerifiedAliasFilterDisabled()
			? self::LEGACY_PLACEHOLDER_LIST_CACHE_KEY
			: self::VERIFIED_PLACEHOLDER_LIST_CACHE_KEY;
	}

	private function getPlaceholderListCacheKeyByHcmLinkCompanyId(int $hcmLinkCompanyId): string
	{
		return $this->getPlaceholderListCacheKey() . "_hcm_link_company_{$hcmLinkCompanyId}";
	}
}
