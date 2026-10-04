<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Message;

/**
 * What became of one attempt to hand a letter to the classifying engine. The gateway only tells the
 * cases apart; what to do with each of them is decided by the caller.
 */
enum ClassificationOutcome
{
	/** The letter reached the queue of the engine; the labels, if any, arrive later through a callback. */
	case Scheduled;

	/** No classify engine on the portal: a switched off ai is a normal state, not a failure. */
	case ConfigurationRefusal;

	/** The portal refuses classification for good: agreement, tariff, expired provider, spent quota. */
	case PolicyRefusal;

	/** The request did not go through, and the same letter may go through the next time. */
	case TemporaryFailure;
}
