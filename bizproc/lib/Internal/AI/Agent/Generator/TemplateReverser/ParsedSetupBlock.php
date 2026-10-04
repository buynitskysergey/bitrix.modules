<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AI\Agent\Generator\TemplateReverser;

final readonly class ParsedSetupBlock
{
	/**
	 * @param list<string> $constantIds ids of the elements of the block, in the order they stand in
	 * @param array<string, WizardConstantElement> $constantElements what every element of the block states
	 *        about its constant apart from the constant itself, by the id of the constant
	 */
	public function __construct(
		public ?string $title,
		public ?string $description,
		public array $constantIds,
		public array $constantElements,
	) {}
}
