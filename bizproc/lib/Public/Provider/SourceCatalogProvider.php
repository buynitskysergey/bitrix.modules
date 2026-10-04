<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Provider;

use Bitrix\Bizproc\Public\DataView\Dto\SourceDescriptor;
use Bitrix\Bizproc\Public\DataView\Dto\SourceField;
use Bitrix\Bizproc\Public\DataView\Dto\SourceRef;
use Bitrix\Bizproc\Public\DataView\Dto\SourceRelation;
use Bitrix\Bizproc\Public\DataView\Exception\SourceUnavailableException;
use Bitrix\Bizproc\Public\DataView\Interface\DataSourceProvider;
use Bitrix\Bizproc\Public\DataView\Interface\StampConstantProvider;
use Bitrix\Bizproc\Public\DataView\Interface\TemplateContextCapableProvider;
use Bitrix\Bizproc\Public\DataView\Registry\DataSourceProviderRegistry;
use Bitrix\Main\DI\ServiceLocator;

final class SourceCatalogProvider
{
	private DataSourceProviderRegistry $providerRegistry;

	public function __construct()
	{
		$this->providerRegistry = ServiceLocator::getInstance()->get('bizproc.service.dataView.providerRegistry');
	}

	/**
	 * @param int|null $templateId context of the catalog; null or a non-positive value means none
	 * @return SourceDescriptor[]
	 */
	public function getSources(int $actorId, ?int $templateId = null): array
	{
		$sources = [];

		foreach ($this->providerRegistry->getAvailableProviders() as $provider)
		{
			foreach ($this->collectSources($provider, $actorId, $templateId) as $descriptor)
			{
				$sources[] = $descriptor;
			}
		}

		return $sources;
	}

	public function getStampConstants(int $actorId, ?int $templateId = null): array
	{
		$constants = [];

		foreach ($this->providerRegistry->getAvailableProviders() as $provider)
		{
			if ($provider instanceof StampConstantProvider)
			{
				array_push(
					$constants,
					...$provider->getAvailableStampConstants($actorId, $templateId),
				);
			}
		}

		return $constants;
	}

	/**
	 * @param int|null $templateId context the source is requested in; availability is checked
	 *                             against the catalog of that very context
	 * @return array{fields: SourceField[], relations: SourceRelation[]}
	 * @throws SourceUnavailableException
	 */
	public function getSchema(SourceRef $source, int $actorId, ?int $templateId = null): array
	{
		if (!$this->isSourceAvailableToActor($source, $actorId, $templateId))
		{
			throw new SourceUnavailableException(sprintf(
				'DataView source "%s.%s" is not available to the current actor',
				$source->module,
				$source->entity,
			));
		}

		$provider = $this->providerRegistry->get($source->module);
		if ($provider === null)
		{
			throw new SourceUnavailableException(
				sprintf('DataView source provider for module "%s" is unavailable', $source->module)
			);
		}

		return [
			'fields' => $provider->getSourceSchema($source)->getFields(),
			'relations' => $provider->getRelations($source),
		];
	}

	private function isSourceAvailableToActor(SourceRef $source, int $actorId, ?int $templateId): bool
	{
		$provider = $this->providerRegistry->get($source->module);
		if ($provider === null)
		{
			return false;
		}

		$requested = $this->sourceIdentity($source->module, $source->entity, $source->params);

		foreach ($this->collectSources($provider, $actorId, $templateId) as $descriptor)
		{
			if ($this->sourceIdentity($descriptor->module, $descriptor->entity, $descriptor->params) === $requested)
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * A provider aware of a template context answers with its contextual catalog; everything else,
	 * including every external provider, is asked exactly as it was before the context existed.
	 *
	 * @return SourceDescriptor[]
	 */
	private function collectSources(DataSourceProvider $provider, int $actorId, ?int $templateId): array
	{
		if ($templateId !== null && $templateId > 0 && $provider instanceof TemplateContextCapableProvider)
		{
			return $provider->getAvailableSourcesForTemplate($templateId, $actorId);
		}

		return $provider->getAvailableSources($actorId);
	}

	private function sourceIdentity(string $module, string $entity, array $params): string
	{
		array_walk_recursive($params, static function (&$value): void {
			$value = is_scalar($value) ? (string)$value : $value;
		});
		$this->ksortRecursive($params);

		return $module . '::' . $entity . '::' . (string)json_encode($params);
	}

	private function ksortRecursive(array &$params): void
	{
		foreach ($params as &$value)
		{
			if (is_array($value))
			{
				$this->ksortRecursive($value);
			}
		}
		unset($value);
		ksort($params);
	}
}
