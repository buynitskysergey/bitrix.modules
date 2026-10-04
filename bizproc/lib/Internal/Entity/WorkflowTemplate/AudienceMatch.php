<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Entity\WorkflowTemplate;

/**
 * Whether the employee who started the process belonged to the pilot audience at that moment.
 *
 * The third value is what makes the coverage divergence countable at all. NotApplicable belongs to a
 * start where the question was never asked - an automatic start, a child process, a debug run, a portal
 * with the feature switched off - and without it the whole automatic traffic of a portal would land in
 * the denominator of the metric as "did not belong", leaving no way to tell "checked and did not belong"
 * from "never checked".
 */
enum AudienceMatch: string
{
	case Matched = 'matched';
	case NotMatched = 'not_matched';
	case NotApplicable = 'not_applicable';
}
