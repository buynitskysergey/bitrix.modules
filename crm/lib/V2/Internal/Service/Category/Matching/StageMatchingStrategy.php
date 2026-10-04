<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\Category\Matching;

use Bitrix\Crm\V2\Internal\Entity\Category\StageData;

/**
 * How the stages a caller sent are matched against the stages the category has: the one question
 * {@see \Bitrix\Crm\V2\Internal\Service\Category\StageSetReplacer} does not answer itself.
 *
 * The two answers differ in what makes a stage "the same one". REST matches by the identifier the
 * client sent back, so a renamed stage keeps its settings and its permissions; the tool of the AI
 * assistant has no identifiers in its contract and matches by position, so the n-th stage of the
 * input becomes the n-th process stage of the category. Everything both do the same way - the
 * permission, the invariant, the transaction, the order of the writes - belongs to the replacer.
 *
 * An implementation reaches neither storage nor permissions: it turns input into a plan and nothing
 * else, which is what makes it fully coverable by unit tests.
 *
 * @internal
 */
interface StageMatchingStrategy
{
	/**
	 * The plan that brings $current to the state $input asks for, or the refusal that stops the
	 * replacement.
	 *
	 * @param StageData[] $current the stages of the category now, ordered by `sort` and then by
	 *        stage record id
	 * @param array<int, mixed> $input the stages the caller sent, in the shape the strategy defines
	 */
	public function match(array $current, array $input): StageChangePlan;
}
