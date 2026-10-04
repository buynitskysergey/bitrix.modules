<?php

namespace Bitrix\Crm\Settings\Traits;

trait EnableFactory
{
	/**
	 * Return true if new interface and api through Service\Factory is used to process this entity type.
	 *
	 * @deprecated New API is always enabled; kept as a compatibility shim for portal customizations.
	 * @return bool
	 */
	public function isFactoryEnabled(): bool
	{
		return true;
	}

	/**
	 * Set state of isFactoryEnabled setting.
	 *
	 * @deprecated New API is always enabled; toggling is no longer supported. Kept as a compatibility shim.
	 * @param bool $isEnabled
	 */
	public function setFactoryEnabled(bool $isEnabled): void
	{
	}
}
