<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\AiAgentGrid\Upgrade;

use CBPHelper;

/**
 * Merges the reference (new) template constants with the current copy constants
 * when upgrading a launched AI-agent copy to a newer template version.
 *
 * Rules (mirror of the value-transfer already done in
 * CBPWorkflowTemplateLoader::importTemplateFromArray()):
 *  - The merge key is the stable constant code (the CONSTANTS array key); the
 *    meaning of a code is never reinterpreted.
 *  - For a code present in both, the copy's value (`Default`) is carried over
 *    onto the reference definition (which may have a new type/label/required
 *    flag), so user settings survive the upgrade. Exception: for an optional
 *    constant whose copy value is empty, the reference `Default` is kept (not
 *    blanked), so a reference-provided default such as a schedule
 *    (RunAt/ScheduleType) is not silently lost. Required constants keep the
 *    copy value even when empty.
 *  - Constants removed in the new version (present only in the copy) are dropped
 *    silently — they neither transfer nor block.
 *  - Constants new in the reference keep their reference `Default`.
 *
 * The service only computes the target set; it never writes to the database.
 */
class ConstantMergeService
{
	/**
	 * @param array $referenceConstants New reference (system template) CONSTANTS.
	 * @param array $copyConstants Current launched-copy CONSTANTS.
	 */
	public function merge(array $referenceConstants, array $copyConstants): ConstantMergeResult
	{
		$target = [];
		$newRequiredMissing = [];

		foreach ($referenceConstants as $code => $definition)
		{
			$isCarriedOver = array_key_exists($code, $copyConstants);
			if ($isCarriedOver)
			{
				$copyValue = $copyConstants[$code]['Default'] ?? null;
				$isRequired = CBPHelper::getBool($definition['Required'] ?? false);

				// Carry the copy value onto the (possibly updated) definition, EXCEPT for an
				// optional constant whose copy value is empty: keep the reference Default instead
				// of blanking it, so a reference-provided default (e.g. a schedule's
				// RunAt/ScheduleType) is not silently lost. Required constants keep the copy value
				// even when empty (the user is expected to have set — or intentionally cleared — it).
				if ($isRequired || !CBPHelper::isEmptyValue($copyValue))
				{
					$definition['Default'] = $copyValue;
				}
			}

			$target[$code] = $definition;

			if (!$isCarriedOver && $this->isRequiredAndEmpty($definition))
			{
				$newRequiredMissing[] = (string)$code;
			}
		}

		return new ConstantMergeResult($target, $newRequiredMissing);
	}

	private function isRequiredAndEmpty(array $definition): bool
	{
		$isRequired = CBPHelper::getBool($definition['Required'] ?? false);
		$value = $definition['Default'] ?? null;

		return $isRequired && CBPHelper::isEmptyValue($value);
	}
}
