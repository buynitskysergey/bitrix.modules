<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Service\WorkflowTemplate\Dto;

/**
 * What a pilot publication is asked with: the same executable fields an ordinary publication carries,
 * the template they belong to, the audience the version is published to and who publishes it.
 *
 * The document type is not part of the request: it is taken from the template row, so a request cannot
 * retarget a template at a foreign entity.
 */
final readonly class PilotPublicationRequest
{
	/**
	 * @param array $executableFields TEMPLATE, PARAMETERS, VARIABLES and CONSTANTS of the template
	 * @param string[] $accessCodes
	 * @param bool $confirmedEmptySettings the publisher has been told that the settings the scheme refers
	 *     to are still empty and has to fill them in right after the publication. Only meant for a
	 *     template with no common version: while it has one, an empty setting is a refusal instead.
	 */
	public function __construct(
		public int $templateId,
		public array $executableFields,
		public array $accessCodes,
		public int $publisherId,
		public bool $confirmedEmptySettings = false,
	)
	{
	}
}
