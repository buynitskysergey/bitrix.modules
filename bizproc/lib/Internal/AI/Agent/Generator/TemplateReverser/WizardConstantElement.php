<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AI\Agent\Generator\TemplateReverser;

/**
 * What an element of the setup wizard states about its constant apart from the constant itself: whether the
 * person setting the agent up has to fill it in, and what it is filled with to begin with. The two stand
 * inside the 'blocks' of the setup activity and may disagree with 'CONSTANTS' of the same template - which is
 * why the format carries them at the wizard level too ('wizard_required', 'wizard_default' of ConstantConfig).
 *
 * The rest of an element - its label, its type, its hint, its options, the settings of its field type - the
 * wizard and the constant state alike in every shipped agent, and the format carries it once, at the constant.
 */
final readonly class WizardConstantElement
{
	/**
	 * @param string|array $default an element carries no null and no scalar of another type: the wizard states
	 *        its default as a string or an array, so anything else in that place is read as an empty value
	 */
	public function __construct(
		public bool $required,
		public string|array $default,
	) {}
}
