<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Item\Interface;

use Bitrix\Crm\V2\Public\Entity\Item\Company;

interface HasCompanyInterface
{
	public function getCompanyId(): ?int;

	public function setCompanyId(?int $companyId): static;

	public function getCompany(): ?Company;
}
