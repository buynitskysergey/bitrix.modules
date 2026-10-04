<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Signature\Template;

use Bitrix\Main\Result;

interface SignatureTemplateProvider
{
	/** A stable token type owned by this provider, for example "macro" or "image". */
	public function getType(): string;

	/** Expected validation errors must be safe to expose to the caller. */
	public function canonicalize(string $template): Result;

	/** Dynamic values must be encoded for their destination in the returned HTML. */
	public function resolve(string $template, SignatureTemplateContext $context): Result;
}
