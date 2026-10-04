<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Signature\Template\Macro;

use Bitrix\Mail\Internal\Service\Signature\Template\SignatureTemplateContext;

interface SignatureMacroValueProvider
{
	/**
	 * @param string[] $ids Empty means every value supported by the provider.
	 * @return array<string, string> Values indexed by catalog item id.
	 */
	public function getValues(SignatureTemplateContext $context, array $ids = []): array;
}
