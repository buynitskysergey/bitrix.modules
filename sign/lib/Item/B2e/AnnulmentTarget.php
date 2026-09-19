<?php

namespace Bitrix\Sign\Item\B2e;

/**
 * The signing a grid row speaks about, as opposed to the member the row displays.
 *
 * The two differ in one case only - the company safe, the representative row of an
 * employee-initiated document - and there the row carries the signer's address and mark
 * while still showing the representative. Everywhere else the target repeats the
 * displayed member field for field.
 *
 * @see \Bitrix\Sign\Service\B2e\AnnulmentTargetService
 */
final readonly class AnnulmentTarget
{
	/**
	 * @param string $uid address the row hands to the annul endpoints.
	 * @param bool $annulled mark the row shows, and the one the status cell reads.
	 * @param bool $eligible signer role and completed status of the target; the document
	 *        owner scope is not part of it and is applied by the caller.
	 */
	public function __construct(
		public string $uid,
		public bool $annulled,
		public bool $eligible,
	)
	{
	}
}
