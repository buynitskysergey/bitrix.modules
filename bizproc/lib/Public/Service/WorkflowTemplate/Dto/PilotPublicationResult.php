<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Service\WorkflowTemplate\Dto;

use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * The outcome of a pilot publication: the stored snapshot and the revision it was taken at. The error
 * flow stays the standard one of {@see Result}; the audience a failed publication was asked with is
 * carried by the error itself, so a repeat costs the publisher nothing but a confirmation.
 */
final class PilotPublicationResult extends Result
{
	private function __construct(
		public readonly ?int $pilotId = null,
		public readonly string $revision = '',
	)
	{
		parent::__construct();
	}

	public static function createSuccess(int $pilotId, string $revision): self
	{
		return new self($pilotId, $revision);
	}

	public static function createError(Error $error): self
	{
		$result = new self();
		$result->addError($error);

		return $result;
	}
}
