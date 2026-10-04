<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\Activity;

/**
 * Document context of the node whose children are being validated, published for the duration of that verdict
 * instead of being passed to it.
 *
 * A parameter would be the obvious way, and it is not available: the verdict is asked through the legacy static
 * contract {@see \CBPActivity::validateChild()}, which activities of other modules and of a portal of its own
 * override with a signature of their own - a parameter added there makes every one of those declarations
 * incompatible, which PHP reports as a fatal error while the file is being included.
 *
 * The context belongs to one verdict and to nothing else: {@see \CBPWorkflowTemplateLoader::validateTemplate()}
 * publishes it around every single call and takes it back right after, so anything asked outside that call
 * keeps the context-free answer it always gave.
 */
final class ChildValidationContext
{
	/** @var array|null [moduleId, entity, documentType] of the node whose children are being validated */
	private static ?array $documentType = null;

	/**
	 * Runs $verdict with $documentType published as the context. The previous context is restored whatever
	 * $verdict does, so a nested validation - an activity validating a template of its own - cannot leave the
	 * outer pass reading a context that is not its own.
	 *
	 * @param array|null $documentType [moduleId, entity, documentType], null for "no context known".
	 */
	public static function withDocumentType(?array $documentType, callable $verdict): mixed
	{
		$previousDocumentType = self::$documentType;
		self::$documentType = $documentType;

		try
		{
			return $verdict();
		}
		finally
		{
			self::$documentType = $previousDocumentType;
		}
	}

	/** @return array|null Context of the verdict being answered, null outside one. */
	public static function getDocumentType(): ?array
	{
		return self::$documentType;
	}
}
