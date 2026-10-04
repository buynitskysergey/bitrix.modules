<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Service\WorkflowTemplate\Dto;

use Bitrix\Main\Type\DateTime;

/**
 * The pilot state of a template as the editor needs it: whether a pilot is running, who published it and
 * when, how large its audience is, whether the template has a common version, whether its settings are
 * frozen, and the executable fields the canvas fills the pilot scheme from.
 *
 * Every field of a template without a pilot is empty, and so is every field while the feature is off: the
 * state is asked once and answers the whole question, so no caller has to know the rule of the visibility.
 */
final readonly class PilotEditorState
{
	/**
	 * @param array{TEMPLATE: array, PARAMETERS: array, VARIABLES: array, CONSTANTS: array}|null $executableFields
	 *     null while there is no pilot, or while the feature is off
	 */
	public function __construct(
		public bool $hasPilot,
		public ?int $pilotId,
		public ?int $publishedBy,
		public ?DateTime $publishedAt,
		public ?int $audienceCount,
		public bool $hasCommonVersion,
		public bool $settingsFrozen,
		public ?array $executableFields,
	)
	{
	}
}
