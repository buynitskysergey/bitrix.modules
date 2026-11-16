<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Document;

/**
 * How many nodes of a tree of one knowledge base an answer may carry before it refuses to carry the
 * tree at all.
 *
 * The number lives here because both sides of the layer boundary go by it: the provider of the
 * ordinary circuit caps its answer by it and publishes it further as `TreeProvider::TREE_MAX_NODES`,
 * and ScopedTreeReader of the public circuit caps its own by the same number without looking up into
 * the layer above. One declaration read from both - a second one with a note asking to keep the two
 * equal is how they stop being equal.
 */
final class TreeSizeLimit
{
	public const MAX_NODES = 5000;
}
