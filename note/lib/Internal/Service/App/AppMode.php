<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\App;

/**
 * Which contour the knowledge-base application is mounted for.
 *
 * The two contours share one bootstrap payload on purpose: a value that exists in only one of them
 * is a sign it belongs to the component that resolves it, not to the shared service.
 */
enum AppMode: string
{
	case Workspace = 'workspace';
	case PublicLink = 'public';

	public function isPublic(): bool
	{
		return $this === self::PublicLink;
	}
}
