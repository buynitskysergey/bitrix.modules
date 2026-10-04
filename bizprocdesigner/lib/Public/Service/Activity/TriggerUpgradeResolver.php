<?php

declare(strict_types=1);

namespace Bitrix\BizprocDesigner\Public\Service\Activity;

use Bitrix\Bizproc\Starter\Dto\TriggerUpgradeDto;

/**
 * Single source of truth for the deprecated-to-actual trigger upgrade inside the editor.
 *
 * The owner module of the node's document declares the upgrade in
 * {@see \Bitrix\Bizproc\Starter\ModuleSettings::getTriggerUpgradeMap()}; the designer stays module-agnostic
 * and never names a trigger class of its own. Both surfaces that touch node settings go through this class:
 * the settings form is built from the upgraded type, the settings save writes it into the template. If they
 * resolved the type apart, the form would offer controls the save path does not understand.
 *
 * Created with `new` instead of being resolved from the private service locator of the module
 * ({@see \Bitrix\BizprocDesigner\Internal\Service\Container}): it is stateless and depends on nothing, so a
 * service id would add no wiring - only a failure mode. It belongs to lib/Public next to the save command,
 * which other modules may reach without loading this module, and a module that is not loaded has no
 * .settings.php applied ({@see \Bitrix\Main\Loader::includeModule()}). The instance stays substitutable where
 * that matters: the save handler takes it through its constructor.
 */
final class TriggerUpgradeResolver
{
	/**
	 * Target type of the node and the properties the upgrade adds to it; the source type with no properties
	 * when the node is not upgraded. The upgrade is one way - an actual type is never a map key, so it can
	 * never resolve back to the deprecated one.
	 *
	 * @param array $nodeDocumentType Target document of the node, see {@see self::resolveNodeDocumentType()}.
	 *
	 * @return array{type: string, properties: array}
	 */
	public function resolveUpgradedType(string $sourceType, array $nodeDocumentType): array
	{
		$notUpgraded = ['type' => $sourceType, 'properties' => []];

		$normalizedSource = $this->normalizeType($sourceType);

		// Only a trigger is ever upgraded, and the type of the node tells that without asking the owner
		// module - the same naming rule tells triggers apart in a stored template
		// (\Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTriggerTable::filterTriggersByActivities()).
		if (!str_ends_with($normalizedSource, 'trigger'))
		{
			return $notUpgraded;
		}

		// The document service throws on an incomplete document type, and a node whose target document
		// cannot be told has no owner module to ask for an upgrade anyway.
		if (!$this->isCompleteDocumentType($nodeDocumentType))
		{
			return $notUpgraded;
		}

		// The document type reaches the document service as a module id and a class name, so a value made up
		// by the client is refused there by an exception rather than by a null: an unknown owner module means
		// no upgrade, exactly as a module declaring none.
		try
		{
			$moduleSettings = \CBPRuntime::getRuntime()
				->getDocumentService()
				->getStarterModuleSettings($nodeDocumentType)
			;
		}
		catch (\Throwable)
		{
			return $notUpgraded;
		}

		// The upgrade map came with this feature: a portal whose bizproc is older than this module has no
		// such contract at all, and the node stays as it is instead of taking down the settings of any node.
		if ($moduleSettings === null || !method_exists($moduleSettings, 'getTriggerUpgradeMap'))
		{
			return $notUpgraded;
		}

		foreach ($moduleSettings->getTriggerUpgradeMap() as $deprecatedType => $upgrade)
		{
			if ($this->normalizeType((string)$deprecatedType) !== $normalizedSource)
			{
				continue;
			}

			// An upgrade of another shape means the owner module speaks another version of the contract, and
			// a node is better left as it is than rebuilt as a guessed type.
			if (!$upgrade instanceof TriggerUpgradeDto || $upgrade->triggerType === '')
			{
				return $notUpgraded;
			}

			return [
				'type' => $upgrade->triggerType,
				'properties' => $upgrade->properties,
			];
		}

		return $notUpgraded;
	}

	/**
	 * Upgrade properties fill in only what the node does not answer itself: a missing form field and an
	 * unknown value both extract as an empty value, so an empty value carries no user choice and must not
	 * shadow the behaviour the upgraded node had. A filled value always wins, so an explicit choice made in
	 * the form of a deprecated node survives the upgrade.
	 */
	public function applyUpgradeProperties(array $properties, array $upgradeProperties): array
	{
		foreach ($upgradeProperties as $name => $value)
		{
			if (\CBPHelper::isEmptyValue($properties[$name] ?? null))
			{
				$properties[$name] = $value;
			}
		}

		return $properties;
	}

	/**
	 * A node-workflow template is bound to the wrapper Workflow document, not to the trigger's real target
	 * document, so the document type of the template cannot resolve the owner module of the node. The trigger
	 * keeps its target in its own Document property (generic bizproc `module@entity@type` reference).
	 * Candidates are read in the caller's priority order; the template document type closes the list for a
	 * node with no explicit target (e.g. the current document).
	 *
	 * @param array<mixed> $documentCandidates
	 */
	public function resolveNodeDocumentType(array $documentCandidates, array $fallbackDocumentType): array
	{
		foreach ($documentCandidates as $document)
		{
			$documentType = $this->parseComplexDocumentType($document);
			if ($documentType !== null)
			{
				return $documentType;
			}
		}

		return $fallbackDocumentType;
	}

	/**
	 * Types are compared with the CBP prefix and the case ignored: the client may spell the type either way,
	 * while the template keeps the canonical spelling of the activity.
	 */
	public function isSameType(string $left, string $right): bool
	{
		return $this->normalizeType($left) === $this->normalizeType($right);
	}

	private function normalizeType(string $type): string
	{
		$normalized = mb_strtolower(trim($type));

		return str_starts_with($normalized, 'cbp') ? substr($normalized, 3) : $normalized;
	}

	private function parseComplexDocumentType(mixed $document): ?array
	{
		if (!is_string($document) || !str_contains($document, '@'))
		{
			return null;
		}

		$documentType = explode('@', $document);

		return $this->isCompleteDocumentType($documentType) ? $documentType : null;
	}

	private function isCompleteDocumentType(array $documentType): bool
	{
		if (count($documentType) !== 3)
		{
			return false;
		}

		foreach ($documentType as $part)
		{
			if (!is_string($part) || $part === '')
			{
				return false;
			}
		}

		return true;
	}
}
