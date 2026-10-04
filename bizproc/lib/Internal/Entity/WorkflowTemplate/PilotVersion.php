<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Entity\WorkflowTemplate;

use Bitrix\Bizproc\Internal\Entity\Document\DocumentComplexType;
use Bitrix\Main\Type\DateTime;

/**
 * A pilot version of a workflow template: the executable fields published to a limited audience and
 * the revision they were taken at. The template name and description are not part of it - they are
 * metadata of the template, shared by both versions.
 *
 * The audience belongs to the version, so it is carried by the same object: a version without its
 * audience is not a state the storage may hold.
 */
final readonly class PilotVersion
{
	/**
	 * @param array $executableFields TEMPLATE, PARAMETERS, VARIABLES and CONSTANTS of the template
	 * @param string[] $accessCodes
	 */
	public function __construct(
		public int $templateId,
		public DocumentComplexType $documentType,
		public array $executableFields,
		public string $revision,
		public int $createdBy,
		public DateTime $created,
		public array $accessCodes,
		public ?int $id = null,
	)
	{
	}
}
