<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Signature\Template\Macro;

interface CompanyMacroDataSource
{
	/**
	 * @param string[] $ids
	 * @return array{name?: string, legalAddress?: string, actualAddress?: string}|null
	 */
	public function load(int $userId, array $ids): ?array;
}
