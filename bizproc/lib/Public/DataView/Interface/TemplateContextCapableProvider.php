<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\DataView\Interface;

/**
 * Secondary capability of a data source provider: a catalog that depends on a workflow template.
 * A provider implementing it is asked for its catalog by this method instead of
 * getAvailableSources() whenever the catalog is requested with a template context.
 */
interface TemplateContextCapableProvider
{
	/**
	 * Full source catalog of the provider for the given template context.
	 * Replaces getAvailableSources() when the catalog is requested with a template context.
	 *
	 * @return \Bitrix\Bizproc\Public\DataView\Dto\SourceDescriptor[]
	 */
	public function getAvailableSourcesForTemplate(int $templateId, int $actorId): array;
}
