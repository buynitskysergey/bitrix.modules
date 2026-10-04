<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\AiAgentGrid\Upgrade;

use Bitrix\Main\Error;
use Bitrix\Main\Result;

/**
 * Result of AgentUpgradeService::upgrade() (ALG-01).
 *
 * Carries the outcome status plus, for the NeedsReview path (the upgrade master shown for
 * review before applying), the current constant values to pre-fill, the codes of the
 * required constants still missing a value, and the reference template id whose setup
 * blocks describe the editable constants. For the Updated path it carries the new
 * installed version (reference revision).
 */
class AgentUpgradeResult extends Result
{
	private UpgradeStatus $status = UpgradeStatus::Failed;

	/** @var array<string, mixed> Current values to pre-fill the review master, keyed by constant code. */
	private array $values = [];

	/** @var list<string> */
	private array $requiredConstants = [];

	/** @var list<string> */
	private array $invalidConstants = [];

	private int $referenceTemplateId = 0;

	private ?string $installedVersion = null;

	public static function updated(string $installedVersion): self
	{
		$result = new self();
		$result->status = UpgradeStatus::Updated;
		$result->installedVersion = $installedVersion;

		return $result;
	}

	/**
	 * The upgrade master is always shown for review before applying (ADR §10.3). Returned on
	 * the first call (no submitted values) and whenever a submit still leaves a required
	 * constant unset (server re-validation — the UI is not the source of truth). The
	 * needs-setup case (new required constants without a value) is the sub-case with a
	 * non-empty $requiredConstants.
	 *
	 * @param array<string, mixed> $values Current constant values to pre-fill, keyed by code.
	 * @param list<string> $requiredConstants Codes of required constants still missing a value
	 *   (empty when nothing is missing); marks them as mandatory in the master.
	 * @param int $referenceTemplateId System template whose setup blocks describe the editable
	 *   constants to render in the master.
	 * @param list<string> $invalidConstants Codes of submitted values that failed field-type
	 *   re-validation (empty on the first call or when all submitted values are valid).
	 */
	public static function needsReview(
		array $values,
		array $requiredConstants,
		int $referenceTemplateId = 0,
		array $invalidConstants = [],
	): self
	{
		$result = new self();
		$result->status = UpgradeStatus::NeedsReview;
		$result->values = $values;
		$result->requiredConstants = $requiredConstants;
		$result->referenceTemplateId = $referenceTemplateId;
		$result->invalidConstants = $invalidConstants;

		return $result;
	}

	public static function conflict(Error $error): self
	{
		$result = new self();
		$result->status = UpgradeStatus::Conflict;
		$result->addError($error);

		return $result;
	}

	public static function failed(Error $error): self
	{
		$result = new self();
		$result->status = UpgradeStatus::Failed;
		$result->addError($error);

		return $result;
	}

	public function getStatus(): UpgradeStatus
	{
		return $this->status;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getValues(): array
	{
		return $this->values;
	}

	/**
	 * @return list<string>
	 */
	public function getRequiredConstants(): array
	{
		return $this->requiredConstants;
	}

	/**
	 * @return list<string>
	 */
	public function getInvalidConstants(): array
	{
		return $this->invalidConstants;
	}

	public function getReferenceTemplateId(): int
	{
		return $this->referenceTemplateId;
	}

	public function getInstalledVersion(): ?string
	{
		return $this->installedVersion;
	}
}
