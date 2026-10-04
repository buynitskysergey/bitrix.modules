<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\AiAgentGrid\Upgrade;

/**
 * Outcome of an AI-agent copy upgrade attempt (ALG-01).
 *
 * The transport layer (API-01) maps these onto HTTP codes:
 *  - Updated     -> 200 (applied)
 *  - NeedsReview -> 200 (review payload: blocks + current values + required markers; the
 *                        upgrade master is always shown for review before applying — ADR §10.3).
 *  - Conflict    -> 409 (already up to date / reference unavailable / stale — ERR-003/ERR-006)
 *  - Failed      -> 422 (apply error — ERR-005)
 */
enum UpgradeStatus: string
{
	case Updated = 'updated';
	case NeedsReview = 'needs_review';
	case Conflict = 'conflict';
	case Failed = 'failed';
}
