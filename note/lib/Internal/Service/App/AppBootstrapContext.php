<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\App;

/**
 * Everything a contour resolves for itself before the shared bootstrap runs.
 *
 * Only differences live here. Values that both contours would compute the same way belong to
 * AppBootstrapService, so that a newly added mount parameter reaches both contours on its own.
 */
final class AppBootstrapContext
{
	public const DEFAULT_ROUTER_BASE = '/note/';

	/**
	 * @param array $initialCollections Sidebar payload: items, cursor and the permission dictionary.
	 * @param mixed $rawSidebarOptions Raw CUserOptions value, or null where there is no user.
	 * @param mixed $rawTheme Raw CUserOptions value, or null where there is no user.
	 * @param mixed $rawDocumentFullWidth Raw CUserOptions value, or null where there is no user.
	 * @param array|null $pullConfig Pull channel configuration of the contour, null when there is none.
	 */
	public function __construct(
		public readonly AppMode $mode,
		public readonly array $initialCollections,
		public readonly string $routerBase = self::DEFAULT_ROUTER_BASE,
		public readonly int $documentId = 0,
		public readonly bool $isDirectLink = false,
		public readonly ?array $initialSidebarContext = null,
		public readonly ?array $initialFavorites = null,
		public readonly bool $hasSubscriptions = false,
		public readonly int $welcomeDocumentId = 0,
		public readonly mixed $rawSidebarOptions = null,
		public readonly mixed $rawTheme = null,
		public readonly mixed $rawDocumentFullWidth = null,
		public readonly ?string $tariffSliderCode = null,
		public readonly bool $passwordRequired = false,
		public readonly ?array $pullConfig = null,
	)
	{
	}

	/**
	 * A tariff/tool block keeps the interface mounted as a teaser behind the slider, which is why
	 * everything it suppresses is asked here rather than at each call site.
	 */
	public function isTariffBlocked(): bool
	{
		return $this->tariffSliderCode !== null && $this->tariffSliderCode !== '';
	}
}
