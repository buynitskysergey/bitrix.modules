<?php

declare(strict_types=1);

namespace Bitrix\Mail\Helper\Message;

interface MessageSenderSourceProvider
{
	public function loadMessage(int $messageId): ?array;
	public function hasAccess(array &$message, int $userId): bool;
	public function materialize(array $message): ?array;
	public function loadAttachmentRows(int $messageId): array;
	public function resolveFile(int $fileId): array|false|null;
}
