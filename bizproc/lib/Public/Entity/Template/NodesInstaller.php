<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Entity\Template;

class NodesInstaller
{
	public function shouldInstall(): bool
	{
		return true;
	}

	public function getModifiedTime(): int
	{
		return time();
	}

	public function onInstall(int $templateId): void
	{
	}

	public function onUpdate(int $templateId): void
	{
	}

	/**
	 * Per-phrase replacements map, similar to $replace in Loc::getMessage:
	 * ['PHRASE_CODE' => ['#PLACEHOLDER#' => 'value']].
	 * @return array<string, array<string, string>>
	 */
	public function getMessageReplacements(): array
	{
		return [];
	}

	/**
	 * Hook for processing a node phrase text at install time.
	 * $code is the phrase code (without ###), $message is the resolved text from the lang file.
	 * Applies the getMessageReplacements() map by default.
	 */
	public function prepareMessage(string $code, string $message): string
	{
		$replacements = $this->getMessageReplacements()[$code] ?? [];

		return $replacements === [] ? $message : strtr($message, $replacements);
	}
}
