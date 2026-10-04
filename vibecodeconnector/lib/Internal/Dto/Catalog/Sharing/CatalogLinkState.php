<?php

declare(strict_types=1);

namespace Bitrix\Vibecodeconnector\Internal\Dto\Catalog\Sharing;

final readonly class CatalogLinkState
{
	public const AVAILABILITY_AVAILABLE = 'AVAILABLE';
	public const AVAILABILITY_UNAVAILABLE = 'UNAVAILABLE';
	public const UNAVAILABLE_REASON_DIRECT_GLOBAL_ACCESS = 'DIRECT_GLOBAL_ACCESS';

	public function __construct(
		public string $availability,
		public ?string $unavailableReason,
		public ?string $url,
		public ?string $expiresAt,
		public ?bool $requireB24Auth,
	) {
	}

	/**
	 * @return array{
	 *     availability: string,
	 *     unavailableReason: string|null,
	 *     link: array{url: string, expiresAt: string|null, requireB24Auth: bool}|null
	 * }
	 */
	public function toArray(): array
	{
		$link = null;
		if ($this->url !== null && $this->requireB24Auth !== null)
		{
			$link = [
				'url' => $this->url,
				'expiresAt' => $this->expiresAt,
				'requireB24Auth' => $this->requireB24Auth,
			];
		}

		return [
			'availability' => $this->availability,
			'unavailableReason' => $this->unavailableReason,
			'link' => $link,
		];
	}
}
