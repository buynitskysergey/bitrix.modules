<?php

namespace Bitrix\BizprocDesigner\Internal\Integration\AiAssistant\Service;

/**
 * Shared mapping between activity Properties and the agent-facing block fields (title/description).
 *
 * Kept in one place so the forward converter (agent -> draft), the reverse template converter
 * (manual template -> agent) and any future consumer read the description property and compare the
 * user title against the system node name identically. Divergence here would desynchronise the
 * round-trip (an override written on one side but not stripped on the other).
 */
trait AgentBlockNamingTrait
{
	private const COMMENT_PROPERTY_NAME = 'EditorComment';
	private const DOCUMENT_PROPERTY_NAME = 'Document';

	/**
	 * Reads the block description from Properties.EditorComment. Empty/absent value yields null, so the
	 * agent never receives an empty description as a separate field.
	 */
	private function extractDescription(array $properties): ?string
	{
		$comment = (string)($properties[self::COMMENT_PROPERTY_NAME] ?? '');

		return $comment !== '' ? $comment : null;
	}

	/**
	 * Reads the applied document type from Properties.Document — the value a preset writes
	 * (crm@CCrmDocumentDeal@DEAL, ...). It is the functional identity used to recover the preset ID on the
	 * reverse path. Empty/absent or non-string value yields null.
	 */
	private function extractDocument(array $properties): ?string
	{
		$document = $properties[self::DOCUMENT_PROPERTY_NAME] ?? null;

		return is_string($document) && $document !== '' ? $document : null;
	}

	/**
	 * Normalizes a stored PresetId to the agent-facing contract: a non-empty string, or null when the field
	 * is absent/empty/not a string. Both reverse paths read the saved field through it, so an empty value
	 * falls through to the Document-based recovery instead of reaching the agent as a preset.
	 */
	private function normalizePresetId(mixed $presetId): ?string
	{
		return is_string($presetId) && $presetId !== '' ? $presetId : null;
	}

	/**
	 * Whitespace-insensitive comparison of a user-facing title against the system node name. Case is kept
	 * significant on purpose: a deliberate re-casing is a legitimate rename and must survive as an override.
	 */
	private function isSameNodeName(string $title, string $systemName): bool
	{
		return trim($title) === trim($systemName);
	}
}
