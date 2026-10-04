<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Public\Entity\Item\Interface;

use Bitrix\Crm\V2\Public\Entity\Item\EmailCollection;
use Bitrix\Crm\V2\Public\Entity\Item\ImCollection;
use Bitrix\Crm\V2\Public\Entity\Item\PhoneCollection;
use Bitrix\Crm\V2\Public\Entity\Item\WebCollection;

interface HasMultifieldsInterface
{
	public function getPhones(): ?PhoneCollection;

	public function setPhones(PhoneCollection $phones): static;

	public function getEmails(): ?EmailCollection;

	public function setEmails(EmailCollection $emails): static;

	public function getWebs(): ?WebCollection;

	public function setWebs(WebCollection $webs): static;

	public function getIms(): ?ImCollection;

	public function setIms(ImCollection $ims): static;

	public function getHasPhone(): ?bool;

	public function getHasEmail(): ?bool;

	public function getHasImol(): ?bool;
}
