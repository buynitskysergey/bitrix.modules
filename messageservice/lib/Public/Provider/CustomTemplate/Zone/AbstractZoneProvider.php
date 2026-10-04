<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Public\Provider\CustomTemplate\Zone;

use Bitrix\MessageService\Public\Dto\CustomTemplate\CustomTemplateSelectorItem;
use Bitrix\MessageService\Public\Type\CustomTemplate\BindingDescription;
use Bitrix\MessageService\Public\Type\CustomTemplate\ReadableFilterOptions;
use Bitrix\MessageService\Public\Type\CustomTemplate\ReadableScope;
use Bitrix\MessageService\Public\Type\CustomTemplate\TemplateBinding;

/**
 * Zone provider declares ownership of a "zone of visibility" for custom message templates.
 * Each zone (e.g. CRM, booking, calendar) registers its own provider and implements
 * the contract: how to build a TemplateBinding from a scene's raw payload, how to
 * describe it for the UI, how to enrich selector items, and how to check permissions.
 */
abstract class AbstractZoneProvider
{
	abstract public function getId(): string;

	/**
	 * Parse opaque scene payload into a TemplateBinding that locates a template inside this zone.
	 *
	 * @param string $scene Current scene id.
	 * @param array<string, mixed> $sceneRawContext Caller-defined payload, e.g. for CRM:
	 *     ['entityTypeId' => int, 'entityId' => int, 'categoryId' => int].
	 */
	abstract public function buildBinding(string $scene, array $sceneRawContext): TemplateBinding;

	/**
	 * Whether the scene id belongs to this zone's scene registry. The zone owns the notion of a
	 * scene, so it is the only place that can vouch for one. Used to gate create input only —
	 * reads/deletes re-derive the scene from storage and must not be gated by it (a scene can
	 * leave the registry while its templates remain). Defaults to true for zones without a
	 * scene registry; override where scenes are enumerable.
	 */
	public function isValidScene(string $scene): bool
	{
		return true;
	}

	/**
	 * Produce a human-readable description of the binding for the editor UI (titles, breadcrumbs, etc.).
	 */
	abstract public function describeBinding(TemplateBinding $binding): BindingDescription;

	/**
	 * Enrich a selector item with zone-specific view data (badges, labels, visibility flags, etc.)
	 * relative to the current editor binding. Returns the (possibly mutated) item.
	 */
	abstract public function fillSelectorItem(
		CustomTemplateSelectorItem $item,
		TemplateBinding $currentBinding,
	): CustomTemplateSelectorItem;

	/**
	 * Coarse-grained permission gate: whether the user is allowed to manage templates in this zone at all.
	 * Used as the default fallback for every fine-grained can*-method below.
	 */
	abstract public function canManageTemplates(int $userId): bool;

	/**
	 * Whether the user can read templates in the given binding.
	 * Defaults to {@see self::canManageTemplates()}; override if read access is broader than manage.
	 */
	public function canReadTemplates(int $userId, TemplateBinding $target): bool
	{
		return $this->canManageTemplates($userId);
	}

	/**
	 * The authoritative gate for zone-wide reads (grid rows + count, selector top-up + search).
	 * Every zone must express its scope explicitly — there is no data-side fallback. Consistent
	 * with {@see self::canReadTemplates()} by construction: both test the same permission model.
	 */
	abstract public function getReadableScope(int $userId): ReadableScope;

	/**
	 * Labelled options for the grid filter dropdowns (scenes + subjects), built from the same
	 * per-request readable-target computation as {@see self::getReadableScope()} — it issues no
	 * DB query and cannot disclose more than the gate allows. Only the grid calls this.
	 */
	abstract public function getReadableFilterOptions(int $userId): ReadableFilterOptions;

	/**
	 * Whether the user can create a template in the given binding.
	 * Defaults to {@see self::canManageTemplates()}; override for finer-grained rules.
	 */
	public function canAddTemplate(int $userId, TemplateBinding $target): bool
	{
		return $this->canManageTemplates($userId);
	}

	/**
	 * Whether the user can update an existing template in the given binding.
	 * Defaults to {@see self::canManageTemplates()}; override for finer-grained rules.
	 */
	public function canUpdateTemplate(int $userId, TemplateBinding $target): bool
	{
		return $this->canManageTemplates($userId);
	}

	/**
	 * Whether the user can delete an existing template in the given binding.
	 * Defaults to {@see self::canManageTemplates()}; override for finer-grained rules.
	 */
	public function canDeleteTemplate(int $userId, TemplateBinding $target): bool
	{
		return $this->canManageTemplates($userId);
	}
}
