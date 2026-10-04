<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\AI\Agent\Generator\TemplateBuilder;

use Bitrix\Bizproc\Internal\AI\Agent\Generator\AgentConfig\ActivityDescriptorConfig;

/**
 * Tells a node built from real activity data from a node built from registry defaults.
 *
 * The registry itself stays fail-open: an activity missing from this environment silently gets the
 * default icon, color index, size and ports, which is what the workflow designer needs. The
 * generator must not ship such a node: it would overwrite the delivered template with values
 * invented by an incomplete environment. This resolver is the strict view over the same registry,
 * used by the generator and the reverse flow only.
 */
final class StrictActivityResolver
{
	public function __construct(
		private readonly ActivityRegistry $registry,
	) {}

	/**
	 * A node is trustworthy when its activity resolves in this environment, or when the source
	 * descriptor states every field the builder would otherwise read from the registry. A partial
	 * descriptor is not enough: the fields it leaves out still come from the registry defaults.
	 */
	public function isNodeTrustworthy(string $activityType, ?ActivityDescriptorConfig $descriptor): bool
	{
		return $this->registry->hasDescription($activityType) || $this->coversRegistryFields($descriptor);
	}

	/**
	 * Whether this environment can supply what a complex wrapper takes from the registry about the activity
	 * it runs inside itself. The shorthand '_inner_type' leaves the return properties of that activity to
	 * the registry (TemplateBuilder::buildInnerReturnProperties()), and they come out empty when the type
	 * does not resolve here, so the node is then as unfaithful as one built from default icons. A descriptor
	 * does not cover this: return properties are not part of the visual descriptor (TPL-03), so the only
	 * answer is a refusal.
	 *
	 * Asked on the shorthand path alone, because that is the only place the registry is read for an inner
	 * activity: 'Rules' written in the source carry the whole activity, return properties included, and an
	 * empty list of them there is the answer of the author and not of an incomplete environment.
	 */
	public function isInnerActivityTrustworthy(string $innerType): bool
	{
		return $this->registry->hasDescription($innerType);
	}

	private function coversRegistryFields(?ActivityDescriptorConfig $descriptor): bool
	{
		return $descriptor !== null
			&& $descriptor->nodeType !== null
			&& $descriptor->hasIcon()
			&& $descriptor->hasColorIndex()
			&& $descriptor->dimensions !== null
			&& $descriptor->ports !== null
		;
	}
}
