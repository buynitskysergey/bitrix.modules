<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\DataView\Provider;

use Bitrix\Bizproc\FieldType;
use Bitrix\Bizproc\Internal\Service\DocumentField\FieldValueFormatter;
use Bitrix\Bizproc\Internal\Service\StorageField\Converter\ValueFieldConverter;
use Bitrix\Bizproc\Public\DataView\Dto\ExtractQuery;
use Bitrix\Bizproc\Public\DataView\Dto\ResolvedStampConstant;
use Bitrix\Bizproc\Public\DataView\Dto\SourceDescriptor;
use Bitrix\Bizproc\Public\DataView\Dto\SourceField;
use Bitrix\Bizproc\Public\DataView\Dto\SourceRef;
use Bitrix\Bizproc\Public\DataView\Dto\SourceSchema;
use Bitrix\Bizproc\Public\DataView\Dto\StampConstantDescriptor;
use Bitrix\Bizproc\Public\DataView\Exception\SourceUnavailableException;
use Bitrix\Bizproc\Public\DataView\Interface\DataSourceProvider;
use Bitrix\Bizproc\Public\DataView\Interface\PresentableProvider;
use Bitrix\Bizproc\Public\DataView\Interface\StampConstantProvider;
use Bitrix\Bizproc\Public\DataView\Interface\TemplateContextCapableProvider;
use Bitrix\Bizproc\Workflow\Template\WorkflowTemplateDraftTable;
use Bitrix\Bizproc\Workflow\Type\GlobalConst;
use Bitrix\Bizproc\Workflow\Type\GlobalVar;

final class VariablesDataSourceProvider implements
	DataSourceProvider,
	TemplateContextCapableProvider,
	StampConstantProvider,
	PresentableProvider
{
	public const MODULE_ID = 'bizproc';

	public const ENTITY_GLOBAL_VARIABLE = 'globalVariable';
	public const ENTITY_GLOBAL_CONSTANT = 'globalConstant';
	public const ENTITY_TEMPLATE_CONSTANT = 'templateConstant';

	/** @var string[] */
	public const ENTITIES = [
		self::ENTITY_GLOBAL_VARIABLE,
		self::ENTITY_GLOBAL_CONSTANT,
		self::ENTITY_TEMPLATE_CONSTANT,
	];

	/** @var string[] */
	public const STAMP_ENTITIES = [
		self::ENTITY_GLOBAL_CONSTANT,
		self::ENTITY_TEMPLATE_CONSTANT,
	];

	public const VALUE_FIELD = 'VALUE';

	/** @var array<int, array{0: string, 1: string, 2: string}|null> */
	private array $templateDocumentTypes = [];

	/** @var array<int, array<string, mixed>> */
	private array $templateDrafts = [];

	private const SUPPORTED_TYPES = [
		FieldType::STRING,
		FieldType::TEXT,
		FieldType::INT,
		FieldType::DOUBLE,
		FieldType::DATE,
		FieldType::DATETIME,
		FieldType::TIME,
		FieldType::BOOL,
		FieldType::SELECT,
		FieldType::INTERNALSELECT,
		FieldType::USER,
	];

	public function getModuleId(): string
	{
		return self::MODULE_ID;
	}

	/** @return SourceDescriptor[] */
	public function getAvailableSources(int $actorId): array
	{
		return array_merge(
			$this->buildDescriptors(self::ENTITY_GLOBAL_VARIABLE, GlobalVar::getAll([])),
			$this->buildDescriptors(self::ENTITY_GLOBAL_CONSTANT, GlobalConst::getAll([])),
		);
	}

	/** @return SourceDescriptor[] */
	public function getAvailableSourcesForTemplate(int $templateId, int $actorId): array
	{
		if ($templateId <= 0)
		{
			return $this->getAvailableSources($actorId);
		}

		$documentType = $this->resolveTemplateDocumentType($templateId);

		$sources = array_merge(
			$this->buildDescriptors(self::ENTITY_GLOBAL_VARIABLE, GlobalVar::getAll($documentType ?? [])),
			$this->buildDescriptors(self::ENTITY_GLOBAL_CONSTANT, GlobalConst::getAll($documentType ?? [])),
		);

		if ($documentType === null)
		{
			return $sources;
		}

		$templateParams = ['templateId' => $templateId];

		return array_merge(
			$sources,
			$this->buildDescriptors(
				self::ENTITY_TEMPLATE_CONSTANT,
				$this->getTemplateConstants($templateId),
				$templateParams,
			),
		);
	}

	/**
	 * @throws SourceUnavailableException
	 */
	public function getSourceSchema(SourceRef $source): SourceSchema
	{
		return new SourceSchema([$this->buildValueField($source, $this->resolveProperty($source))]);
	}

	/**
	 * @return iterable<array<string, scalar|null>>
	 * @throws SourceUnavailableException
	 */
	public function extract(SourceRef $source, ExtractQuery $query, int $actorId): iterable
	{
		$property = $this->resolveProperty($source);
		$field = $this->buildValueField($source, $property);

		$this->assertRequestedFieldsExist(new SourceSchema([$field]), $query);

		if ($query->limit <= 0)
		{
			return;
		}

		$keyValues = $query->hasKeyIn() ? $this->buildKeyValueSet($query->getKeyValues()) : null;

		$yielded = 0;
		foreach ($this->normalizeValues($property['Default'] ?? null, $field->type, true) as $index => $value)
		{
			if ($keyValues !== null && ($value === null || !isset($keyValues[(string)$value])))
			{
				continue;
			}

			yield [
				'id' => $index + 1,
				self::VALUE_FIELD => $value,
			];

			$yielded++;
			if ($yielded >= $query->limit)
			{
				return;
			}
		}
	}

	public function getRelations(SourceRef $source): array
	{
		return [];
	}

	/**
	 * A row of such a source is one element of the multiple default, so its value is formatted as a
	 * single value of the declared type.
	 *
	 * @param iterable<int|string, array<string, mixed>> $rows
	 * @return iterable<int|string, array<string, string>>
	 * @throws SourceUnavailableException
	 */
	public function present(SourceRef $source, iterable $rows, int $actorId = 0): iterable
	{
		$rows = is_array($rows) ? $rows : iterator_to_array($rows, true);
		if ($rows === [])
		{
			return [];
		}

		$field = $this->buildValueField($source, $this->resolveProperty($source));
		$formatter = FieldValueFormatter::forType($field->type);
		if ($formatter === null)
		{
			return [];
		}

		FieldValueFormatter::prefetchUsers($rows);

		$presented = [];
		foreach ($rows as $rowKey => $row)
		{
			$row = (array)$row;
			$presented[$rowKey] = array_key_exists(self::VALUE_FIELD, $row)
				? [self::VALUE_FIELD => $formatter->format($row[self::VALUE_FIELD])]
				: []
			;
		}

		return $presented;
	}

	/**
	 * @return StampConstantDescriptor[]
	 */
	public function getAvailableStampConstants(int $actorId, ?int $templateId = null): array
	{
		$templateId = (int)$templateId;
		$documentType = $templateId > 0 ? $this->resolveTemplateDocumentType($templateId) : null;

		if ($documentType === null)
		{
			return $this->buildStampDescriptors(self::ENTITY_GLOBAL_CONSTANT, GlobalConst::getAll([]));
		}

		$templateParams = ['templateId' => $templateId];

		return array_merge(
			$this->buildStampDescriptors(
				self::ENTITY_GLOBAL_CONSTANT,
				GlobalConst::getAll($documentType),
				$templateParams,
			),
			$this->buildStampDescriptors(
				self::ENTITY_TEMPLATE_CONSTANT,
				$this->getTemplateConstants($templateId),
				$templateParams,
			),
		);
	}

	/**
	 * @throws SourceUnavailableException
	 */
	public function resolveStampConstant(SourceRef $constant): ResolvedStampConstant
	{
		if (!in_array($constant->entity, self::STAMP_ENTITIES, true))
		{
			throw SourceUnavailableException::sourceEntityUnknown($constant->entity);
		}

		$code = (string)$constant->getParam('code', '');
		$property = $constant->entity === self::ENTITY_GLOBAL_CONSTANT
			? $this->resolveVisibleGlobalConstant($constant, $code)
			: $this->resolveProperty($constant)
		;

		if (\CBPHelper::getBool($property['Multiple'] ?? false))
		{
			throw SourceUnavailableException::stampConstantNotScalar($constant->entity, $code);
		}

		$type = $this->resolveType($property);
		if ($type === null)
		{
			throw SourceUnavailableException::sourceVariableTypeUnsupported(
				$constant->entity,
				$code,
				(string)($property['Type'] ?? ''),
			);
		}

		return new ResolvedStampConstant(
			title: $this->resolveName($code, $property),
			type: $type,
			value: $this->firstNormalizedValue($property['Default'] ?? null, $type),
		);
	}

	/**
	 * Document type of the template, the visibility scope of its globals.
	 *
	 * @return array{0: string, 1: string, 2: string}|null null when the template does not exist
	 */
	private function resolveTemplateDocumentType(int $templateId): ?array
	{
		if (array_key_exists($templateId, $this->templateDocumentTypes))
		{
			return $this->templateDocumentTypes[$templateId];
		}

		$iterator = \CBPWorkflowTemplateLoader::GetList(
			[],
			['ID' => $templateId],
			false,
			false,
			['MODULE_ID', 'ENTITY', 'DOCUMENT_TYPE'],
		);

		$row = $iterator->fetch();
		$documentType = $row ? ($row['DOCUMENT_TYPE'] ?? null) : null;

		return $this->templateDocumentTypes[$templateId] = is_array($documentType) ? $documentType : null;
	}

	/**
	 * @param array<string, array> $properties
	 * @param array<string, scalar> $baseParams reference params shared by the whole group
	 * @return SourceDescriptor[]
	 */
	private function buildDescriptors(string $entity, array $properties, array $baseParams = []): array
	{
		$descriptors = [];
		foreach ($properties as $code => $property)
		{
			if (
				!is_array($property)
				|| !\CBPHelper::getBool($property['Multiple'] ?? false)
				|| $this->resolveType($property) === null
			)
			{
				continue;
			}

			$descriptors[] = new SourceDescriptor(
				module: self::MODULE_ID,
				entity: $entity,
				title: $this->resolveName((string)$code, $property),
				requiresParams: true,
				bounded: true,
				params: array_merge($baseParams, ['code' => (string)$code]),
			);
		}

		return $descriptors;
	}

	/**
	 * @param array<string, array> $properties
	 * @param array<string, scalar> $baseParams reference params shared by the whole group
	 * @return StampConstantDescriptor[]
	 */
	private function buildStampDescriptors(string $entity, array $properties, array $baseParams = []): array
	{
		$descriptors = [];
		foreach ($properties as $code => $property)
		{
			if (!is_array($property) || \CBPHelper::getBool($property['Multiple'] ?? false))
			{
				continue;
			}

			$type = $this->resolveType($property);
			if ($type === null)
			{
				continue;
			}

			$descriptors[] = new StampConstantDescriptor(
				module: self::MODULE_ID,
				entity: $entity,
				params: array_merge($baseParams, ['code' => (string)$code]),
				title: $this->resolveName((string)$code, $property),
				type: $type,
			);
		}

		return $descriptors;
	}

	private function resolveName(string $code, array $property): string
	{
		$name = trim((string)($property['Name'] ?? ''));

		return $name === '' ? $code : $name;
	}

	/**
	 * @throws SourceUnavailableException
	 */
	private function buildValueField(SourceRef $source, array $property): SourceField
	{
		$code = (string)$source->getParam('code', '');
		if (!\CBPHelper::getBool($property['Multiple'] ?? false))
		{
			throw SourceUnavailableException::sourceVariableRemoved($source->entity, $code);
		}

		$type = $this->resolveType($property);
		if ($type === null)
		{
			throw SourceUnavailableException::sourceVariableTypeUnsupported(
				$source->entity,
				$code,
				(string)($property['Type'] ?? ''),
			);
		}

		return new SourceField(
			code: self::VALUE_FIELD,
			title: $this->resolveName($code, $property),
			type: $type,
			multiple: false,
			joinable: true,
			indexed: true,
			presentable: FieldValueFormatter::isPresentableType($type),
		);
	}

	/**
	 * A stamp reference keeps the template it was taken from, so the constant is resolved within the
	 * visibility scope its catalog was built with.
	 *
	 * @return array<string, mixed>
	 * @throws SourceUnavailableException
	 */
	private function resolveVisibleGlobalConstant(SourceRef $constant, string $code): array
	{
		$templateId = (int)$constant->getParam('templateId', 0);
		$documentType = $templateId > 0 ? $this->resolveTemplateDocumentType($templateId) : null;

		$property = $code === '' ? null : GlobalConst::getVisibleById($code, $documentType ?? []);

		if (!is_array($property))
		{
			throw SourceUnavailableException::sourceVariableRemoved($constant->entity, $code);
		}

		return $property;
	}

	/**
	 * @return array<string, mixed>
	 * @throws SourceUnavailableException
	 */
	private function resolveProperty(SourceRef $source): array
	{
		$code = (string)$source->getParam('code', '');

		// A global source reference carries no template, so it resolves outside any visibility scope.
		$property = match ($source->entity)
		{
			self::ENTITY_GLOBAL_VARIABLE => $code === '' ? null : GlobalVar::getById($code),
			self::ENTITY_GLOBAL_CONSTANT => $code === '' ? null : GlobalConst::getById($code),
			self::ENTITY_TEMPLATE_CONSTANT => $this->resolveTemplateConstant($source, $code),
			default => throw SourceUnavailableException::sourceEntityUnknown($source->entity),
		};

		if (!is_array($property))
		{
			throw SourceUnavailableException::sourceVariableRemoved($source->entity, $code);
		}

		return $property;
	}

	private function resolveTemplateConstant(SourceRef $source, string $code): ?array
	{
		$templateId = (int)$source->getParam('templateId', 0);
		if ($templateId <= 0 || $code === '')
		{
			return null;
		}

		$property = $this->getTemplateConstants($templateId)[$code] ?? null;

		return is_array($property) ? $property : null;
	}

	/**
	 * Constants of the template as the editor knows them: the published template wins, and its draft
	 * adds what the editor holds but has not published yet.
	 *
	 * @return array<string, mixed>
	 */
	private function getTemplateConstants(int $templateId): array
	{
		$published = \CBPWorkflowTemplateLoader::getTemplateConstants($templateId);
		$draft = $this->getTemplateDraftData($templateId)['CONSTANTS'] ?? null;

		return is_array($draft) ? $published + $draft : $published;
	}

	/** @return array<string, mixed> */
	private function getTemplateDraftData(int $templateId): array
	{
		if (!array_key_exists($templateId, $this->templateDrafts))
		{
			$this->templateDrafts[$templateId] = WorkflowTemplateDraftTable::getLatestDraftDataByTemplateId(
				$templateId,
			);
		}

		return $this->templateDrafts[$templateId];
	}

	private function resolveType(array $property): ?string
	{
		$type = (string)($property['Type'] ?? '');

		return in_array($type, self::SUPPORTED_TYPES, true) ? $type : null;
	}

	/**
	 * @throws SourceUnavailableException
	 */
	private function assertRequestedFieldsExist(SourceSchema $schema, ExtractQuery $query): void
	{
		$requestedCodes = $query->select;

		$keyField = $query->hasKeyIn() ? $query->getKeyField() : null;
		if ($keyField !== null && $keyField !== '')
		{
			$requestedCodes[] = $keyField;
		}

		foreach ($requestedCodes as $code)
		{
			if ($schema->getField((string)$code) === null)
			{
				throw SourceUnavailableException::sourceFieldRemoved((string)$code);
			}
		}
	}

	/**
	 * Values are yielded one by one so that a caller reading a few of them neither converts nor
	 * holds the whole multiple default.
	 *
	 * @return \Generator<int, scalar|null>
	 */
	private function normalizeValues(mixed $value, string $type, bool $isMultiple): \Generator
	{
		foreach ((is_array($value) ? $value : [$value]) as $element)
		{
			if (\CBPHelper::isEmptyValue($element))
			{
				yield null;

				continue;
			}

			$readValues = ValueFieldConverter::toReadValues($element, $type, $isMultiple);
			if ($readValues === [])
			{
				yield null;

				continue;
			}

			foreach ($readValues as $readValue)
			{
				yield $readValue;
			}
		}
	}

	private function firstNormalizedValue(mixed $value, string $type): mixed
	{
		foreach ($this->normalizeValues($value, $type, false) as $normalized)
		{
			return $normalized;
		}

		return null;
	}

	/**
	 * @return array<string, true> keys of the join filter, indexed for lookup by value
	 */
	private function buildKeyValueSet(array $keyValues): array
	{
		$set = [];
		foreach ($keyValues as $keyValue)
		{
			if (!is_array($keyValue))
			{
				$set[(string)$keyValue] = true;
			}
		}

		return $set;
	}
}
