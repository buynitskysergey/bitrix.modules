<?php

declare(strict_types=1);

namespace Bitrix\Note;

/**
 * Handler of `mobileapp:onJNComponentWorkspaceGet`: the directory the mobile app looks for
 * components and extensions of note in.
 */
class Workspace
{
	public static function getPath(): string
	{
		return '/bitrix/mobileapp/note/';
	}
}
