<?php

declare(strict_types=1);

namespace Bitrix\Mail\Public\Service\LargeAttachment;

use Bitrix\Mail\Helper\Config\Guide;

/**
 * The one-time guides of the large attachment scenario. A consumer that hosts its own compose form needs to
 * know whether a guide was already shown and under which name the answer is kept, so that the same guide is
 * not shown again on another platform.
 */
final class GuideState
{
	/**
	 * The user option category the one-time guides of the mail module live in. A consumer that owns a guide
	 * of this scenario keeps it here as well, so that the guides of one compose form stay in one place.
	 */
	public static function getUserOptionCategory(): string
	{
		return Guide::USER_OPTION_CATEGORY;
	}

	/**
	 * Whether the guide about the first heavy attachment was already shown to the current user.
	 */
	public static function wasAhaShown(): bool
	{
		return Guide::wasLargeAttachmentAhaShown();
	}

	public static function getAhaOptionName(): string
	{
		return Guide::getLargeAttachmentAhaGuideOptionName();
	}

	/**
	 * Whether the current user asked not to be prompted about the scenario after a send any more.
	 */
	public static function wasPostSendPromptSuppressed(): bool
	{
		return Guide::wasLargeAttachmentPostSendPromptSuppressed();
	}

	public static function getPostSendPromptOptionName(): string
	{
		return Guide::getLargeAttachmentPostSendPromptOptionName();
	}
}
