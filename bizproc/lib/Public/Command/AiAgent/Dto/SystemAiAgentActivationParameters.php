<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Command\AiAgent\Dto;

/**
 * Values of a programmatic activation, grouped into named sections.
 *
 * Every section carries values for one declaration source of the system template: `parameters` for its
 * PARAMETERS field and `constants` for its CONSTANTS field. Only declared names are accepted; an unknown
 * name is rejected instead of being silently ignored.
 *
 * A section added later is one more constructor argument with a default and one more entry of
 * {@see self::getSections()}. Command signatures therefore stay unchanged, and validation, defaults and the
 * configuration fingerprint pick the new section up because they iterate sections instead of naming them.
 */
final class SystemAiAgentActivationParameters
{
	public const SECTION_PARAMETERS = 'parameters';

	public const SECTION_CONSTANTS = 'constants';

	/**
	 * @param array<string, mixed> $parameters values of the declared activation parameters
	 * @param array<string, mixed> $constants values of the declared template constants
	 */
	public function __construct(
		public readonly array $parameters = [],
		public readonly array $constants = [],
	)
	{
	}

	/**
	 * Returns the values of every section by its name.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public function getSections(): array
	{
		return [
			self::SECTION_PARAMETERS => $this->parameters,
			self::SECTION_CONSTANTS => $this->constants,
		];
	}
}
