<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\ExternalAccess;

/**
 * What a service of the public circuit is allowed to read: one knowledge base, and no way to name
 * another.
 *
 * The answer is carried by the context the pre-filter builds from a resolved token, and that context
 * lives in Infrastructure - the layer above this one. Type-hinting it here would point the domain at
 * its own transport, so the hint is this interface instead: the domain declares what it needs, the
 * transport brings something that satisfies it, and the dependency points the way the layers agreed
 * on. The binding itself is unchanged - the scope is still wired in at construction and still cannot
 * be left out or passed per call.
 *
 * Narrow on purpose. The link record and the access level belong to the circuit around these
 * services and not to the reading inside them; a getter nothing here calls would only invite a call.
 */
interface PublicScope
{
	public function getCollectionId(): int;
}
