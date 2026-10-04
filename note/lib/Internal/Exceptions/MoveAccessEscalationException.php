<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Exceptions;

// Raised when a move/reparent would open the branch to a subtree share and the actor lacks
// moderator level on the target collection. Carries the typed code so the controller can tell
// the frontend "needs moderator" apart from a plain "move failed".
final class MoveAccessEscalationException extends \RuntimeException
{
}
