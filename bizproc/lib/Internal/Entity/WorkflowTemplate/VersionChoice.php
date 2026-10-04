<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Entity\WorkflowTemplate;

/**
 * Which version of a workflow template acts for the employee starting it, the fingerprint of the scheme
 * that will be executed and the ground the choice stands on.
 *
 * The choice is made anew at every start and is never kept between them: the audience follows the org
 * structure of the portal, so a decision carried over from the previous start would outlive a move
 * between departments.
 *
 * The executable fields travel with the choice instead of being asked for again: the snapshot has
 * already been read to make the choice, and a second read would cost the whole scheme twice.
 *
 * The ground of the choice is data of the diagnostics and is shown to no participant of the process
 */
final readonly class VersionChoice
{
	public const PARAMETER_SCHEMA_TOKEN = '__BizprocParameterSchemaToken';

	/**
	 * @param array|null $executableFields the shape {@see \CBPWorkflowTemplateLoader::loadWorkflowFromArray()}
	 *     reads; null for the common version, which the runtime keeps loading by the template id
	 */
	private function __construct(
		public bool $isPilot,
		public string $revision,
		public AudienceMatch $audienceMatch,
		public ?string $surface,
		public ?array $executableFields,
	)
	{
	}

	/**
	 * @param string $revision the fingerprint of the common scheme; an empty one is a legal answer - a
	 *     template published to a pilot only has no common version at all
	 */
	public static function common(
		AudienceMatch $audienceMatch,
		string $revision = '',
		?string $surface = null,
	): self
	{
		return new self(
			isPilot: false,
			revision: $revision,
			audienceMatch: $audienceMatch,
			surface: $surface,
			executableFields: null,
		);
	}

	/**
	 * The pilot version acts for a participant of its audience and for nobody else, so the ground of the
	 * choice is not an argument here: no other one can bring the pilot scheme.
	 */
	public static function pilot(PilotVersion $version, ?string $surface = null): self
	{
		return self::pilotRuntime(
			$version->templateId,
			$version->executableFields,
			$version->revision,
			$surface,
		);
	}

	public static function pilotRuntime(
		int $templateId,
		array $executableFields,
		string $revision,
		?string $surface = null,
	): self
	{
		return new self(
			isPilot: true,
			revision: $revision,
			audienceMatch: AudienceMatch::Matched,
			surface: $surface,
			executableFields: [
				// the instance stays bound to the live template row, so the id is the one of the template
				'ID' => $templateId,
				'TEMPLATE' => $executableFields['TEMPLATE'] ?? [],
				'PARAMETERS' => $executableFields['PARAMETERS'] ?? [],
				'VARIABLES' => $executableFields['VARIABLES'] ?? [],
				'CONSTANTS' => $executableFields['CONSTANTS'] ?? [],
			],
		);
	}

	public static function parameterSchemaToken(array $parameters): string
	{
		return hash('sha256', serialize($parameters));
	}
}
