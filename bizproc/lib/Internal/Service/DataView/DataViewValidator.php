<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\DataView;

use Bitrix\Bizproc\Internal\Exception\DataView\DataViewValidationException;
use Bitrix\Bizproc\Internal\Exception\DataView\InvalidDataViewDefinitionException;
use Bitrix\Bizproc\Internal\Repository\DataViewRepository\DataViewRepositoryInterface;
use Bitrix\Bizproc\Internal\Service\DataView\Provider\StorageDataSourceProvider;
use Bitrix\Bizproc\Public\DataView\Dto\SourceRef;
use Bitrix\Bizproc\Public\DataView\Exception\KeyTypeMismatchException;
use Bitrix\Bizproc\Public\DataView\Exception\SourceUnavailableException;
use Bitrix\Main\Localization\Loc;

/**
 * Whole-definition validation for save and preview. Unlike {@see ColumnResolver}, which fails on the
 * first violation, the validator accumulates every problem it can reach into a list of normalized
 * DTO-01 {code, field, message} entries. The low-level definition rules stay in ColumnResolver and are
 * reused here: the validator runs the resolver, converts its domain exceptions into the DTO-01 shape,
 * and adds the whole-catalog checks the resolver cannot see: the chain cycle/depth constraints.
 */
final class DataViewValidator
{
	public const CODE_DEFINITION_INVALID = 'DATA_VIEW_DEFINITION_INVALID';
	public const CODE_SOURCE_UNKNOWN = 'DATA_VIEW_SOURCE_UNKNOWN';
	public const CODE_FIELD_UNKNOWN = 'DATA_VIEW_FIELD_UNKNOWN';
	public const CODE_JOIN_KEY_TYPE_MISMATCH = 'DATA_VIEW_JOIN_KEY_TYPE_MISMATCH';
	public const CODE_PERIOD_INVALID = 'DATA_VIEW_PERIOD_INVALID';
	public const CODE_AGGREGATE_FUNCTION_NOT_APPLICABLE = 'DATA_VIEW_AGGREGATE_FUNCTION_NOT_APPLICABLE';
	public const CODE_COLUMN_CODE_DUPLICATE = 'DATA_VIEW_COLUMN_CODE_DUPLICATE';
	public const CODE_STAMP_INVALID = 'DATA_VIEW_STAMP_INVALID';
	public const CODE_COLUMN_FORMULA_INVALID = 'DATA_VIEW_COLUMN_FORMULA_INVALID';
	public const CODE_COLUMN_FORMULA_REFERENCE_INVALID = 'DATA_VIEW_COLUMN_FORMULA_REFERENCE_INVALID';
	public const CODE_COLUMN_FORMULA_FUNCTION_NOT_ALLOWED = 'DATA_VIEW_COLUMN_FORMULA_FUNCTION_NOT_ALLOWED';
	public const CODE_COLUMN_TYPE_CHANGED = 'DATA_VIEW_COLUMN_TYPE_CHANGED';
	public const CODE_CHAIN_CYCLE = 'DATA_VIEW_CHAIN_CYCLE';
	public const CODE_CHAIN_DEPTH_EXCEEDED = 'DATA_VIEW_CHAIN_DEPTH_EXCEEDED';

	private const FIELD_GENERAL = 'general';
	private const FIELD_SOURCES = 'sources';
	private const FIELD_COLUMNS = 'columns';
	private const FIELD_JOIN_KEYS = 'joinKeys';
	private const FIELD_PERIOD = 'period';
	private const FIELD_AGGREGATE = 'aggregate';

	/** @var array<int, true>|null */
	private ?array $knownTypeIds = null;

	/** @var array<int, int[]> view -> view edges, resolved on demand while walking the chain */
	private array $chainEdges = [];

	public function __construct(
		private readonly ColumnResolver $columnResolver,
		private readonly DataViewRepositoryInterface $dataViewRepository,
	) {
	}

	/**
	 * @return array<int, array{code: string, field: string, message: string}>
	 */
	public function validate(array $definition, ?int $targetStorageTypeId = null): array
	{
		return $this->run($definition, $targetStorageTypeId, null)['errors'];
	}

	/**
	 * @param array|null $previousDefinition definition the result storage was built from, passed by the
	 *   caller that updates a stored view: without it a column type change cannot be told from a formula
	 *   change ({@see ColumnResolver::resolve()})
	 * @return array<int, array{code: string, source: string, title: string, type: string, multiple: bool}>
	 *   columns resolved while validating, ready to be reused instead of resolving them again
	 * @throws DataViewValidationException when the definition violates one or more DTO-01 rules
	 */
	public function assertValid(
		array $definition,
		?int $targetStorageTypeId = null,
		?array $previousDefinition = null,
	): array
	{
		['errors' => $errors, 'columns' => $columns] = $this->run($definition, $targetStorageTypeId, $previousDefinition);

		if ($errors !== [])
		{
			throw new DataViewValidationException($errors);
		}

		return $columns;
	}

	/**
	 * @return array{errors: array<int, array{code: string, field: string, message: string}>, columns: array}
	 */
	private function run(array $definition, ?int $targetStorageTypeId, ?array $previousDefinition): array
	{
		// catalog state is re-read per call so that a caller holding a catalog lock sees it under that lock
		$this->knownTypeIds = null;
		$this->chainEdges = [];

		$errors = [];

		[$definitionError, $columns] = $this->runDefinitionChecks($definition, $targetStorageTypeId, $previousDefinition);
		if ($definitionError !== null)
		{
			$errors[] = $definitionError;
		}

		foreach ($this->checkChain($definition, $targetStorageTypeId) as $chainError)
		{
			$errors[] = $chainError;
		}

		return ['errors' => $errors, 'columns' => $columns];
	}

	/**
	 * @return array{0: array{code: string, field: string, message: string}|null, 1: array}
	 */
	private function runDefinitionChecks(
		array $definition,
		?int $targetStorageTypeId,
		?array $previousDefinition,
	): array
	{
		try
		{
			return [null, $this->columnResolver->resolve($definition, $targetStorageTypeId, $previousDefinition)];
		}
		catch (KeyTypeMismatchException)
		{
			return [$this->error(self::CODE_JOIN_KEY_TYPE_MISMATCH), []];
		}
		catch (SourceUnavailableException $exception)
		{
			return [$this->error($this->codeForSourceUnavailable($exception)), []];
		}
		catch (InvalidDataViewDefinitionException $exception)
		{
			return [$this->error($this->codeForInvalidDefinition($exception)), []];
		}
	}

	private function codeForSourceUnavailable(SourceUnavailableException $exception): string
	{
		return match ($exception->getKind())
		{
			SourceUnavailableException::KIND_FIELD => self::CODE_FIELD_UNKNOWN,
			SourceUnavailableException::KIND_DATE_WINDOW => self::CODE_PERIOD_INVALID,
			default => self::CODE_SOURCE_UNKNOWN,
		};
	}

	private function codeForInvalidDefinition(InvalidDataViewDefinitionException $exception): string
	{
		return match ($exception->getViolation())
		{
			InvalidDataViewDefinitionException::VIOLATION_PERIOD => self::CODE_PERIOD_INVALID,
			InvalidDataViewDefinitionException::VIOLATION_AGGREGATE_FN => self::CODE_AGGREGATE_FUNCTION_NOT_APPLICABLE,
			InvalidDataViewDefinitionException::VIOLATION_COLUMN_DUPLICATE => self::CODE_COLUMN_CODE_DUPLICATE,
			InvalidDataViewDefinitionException::VIOLATION_FIELD_UNKNOWN => self::CODE_FIELD_UNKNOWN,
			InvalidDataViewDefinitionException::VIOLATION_STAMP_INVALID => self::CODE_STAMP_INVALID,
			InvalidDataViewDefinitionException::VIOLATION_FORMULA_INVALID => self::CODE_COLUMN_FORMULA_INVALID,
			InvalidDataViewDefinitionException::VIOLATION_FORMULA_REFERENCE => self::CODE_COLUMN_FORMULA_REFERENCE_INVALID,
			InvalidDataViewDefinitionException::VIOLATION_FORMULA_FUNCTION => self::CODE_COLUMN_FORMULA_FUNCTION_NOT_ALLOWED,
			InvalidDataViewDefinitionException::VIOLATION_COLUMN_TYPE_CHANGED => self::CODE_COLUMN_TYPE_CHANGED,
			default => self::CODE_DEFINITION_INVALID,
		};
	}

	/**
	 * A view that reads template-scoped sources materializes that template's data into its result
	 * storage, so a definition written for another template must not read that storage either. Like
	 * {@see ColumnResolver::assertTemplateSourcesOwned()} the rule needs the owner, which the
	 * definition does not carry, so the boundary holding it calls this on its own. Checking the
	 * directly referenced views is enough: the same rule keeps every stored view free of a foreign
	 * template's data, so a referenced view can only carry its own owner's.
	 *
	 * @throws InvalidDataViewDefinitionException
	 */
	public function assertReferencedViewsOwned(array $definition, ?int $ownerTemplateId): void
	{
		foreach ($this->referencedViewTypeIds($definition) as $storageTypeId)
		{
			$view = $this->dataViewRepository->getByStorageTypeId($storageTypeId);
			if ($view === null || !ColumnResolver::hasTemplateScopedSources($view->getDefinition()))
			{
				continue;
			}

			if ($view->getOwnerTemplateId() !== $ownerTemplateId)
			{
				throw new InvalidDataViewDefinitionException(
					sprintf(
						'DataView %d carries data of workflow template %s and is not available to the owner template %s.',
						$storageTypeId,
						$view->getOwnerTemplateId() === null ? 'null' : (string)$view->getOwnerTemplateId(),
						$ownerTemplateId === null ? 'null' : (string)$ownerTemplateId,
					),
					violation: InvalidDataViewDefinitionException::VIOLATION_TEMPLATE_SOURCE_FOREIGN,
				);
			}
		}
	}

	/**
	 * @return array<int, array{code: string, field: string, message: string}>
	 */
	private function checkChain(array $definition, ?int $targetStorageTypeId): array
	{
		$editedTypeId = (int)($targetStorageTypeId ?? 0);
		$this->chainEdges[$editedTypeId] = $this->referencedViewTypeIds($definition);

		return $this->detectChainErrors($editedTypeId);
	}

	/**
	 * Views the given one reads from. Definitions are loaded per node while walking, so the walk
	 * reads only the subgraph reachable from the edited view within CHAIN_DEPTH, not the whole catalog.
	 *
	 * @return int[]
	 */
	private function chainEdgesOf(int $storageTypeId): array
	{
		if (!array_key_exists($storageTypeId, $this->chainEdges))
		{
			$view = $this->dataViewRepository->getByStorageTypeId($storageTypeId);
			$this->chainEdges[$storageTypeId] = $view === null
				? []
				: $this->referencedViewTypeIds($view->getDefinition())
			;
		}

		return $this->chainEdges[$storageTypeId];
	}

	/**
	 * Storage type ids of the definition's sources that are themselves data views.
	 *
	 * @return int[]
	 */
	private function referencedViewTypeIds(array $definition): array
	{
		$knownTypeIds = $this->knownViewTypeIds();
		$referenced = [];
		foreach ((array)($definition['sources'] ?? []) as $source)
		{
			$ref = SourceRef::fromArray((array)$source);
			if (
				$ref->module !== StorageDataSourceProvider::MODULE_ID
				|| $ref->entity !== StorageDataSourceProvider::ENTITY
			)
			{
				continue;
			}

			$storageTypeId = (int)$ref->getParam('storageTypeId', 0);
			if ($storageTypeId > 0 && isset($knownTypeIds[$storageTypeId]))
			{
				$referenced[$storageTypeId] = $storageTypeId;
			}
		}

		return array_values($referenced);
	}

	/**
	 * @return array<int, array{code: string, field: string, message: string}>
	 */
	private function detectChainErrors(int $startTypeId): array
	{
		$flags = ['cycle' => false, 'depth' => false];
		$this->walkChain($startTypeId, [], 1, $flags);

		$errors = [];
		if ($flags['cycle'])
		{
			$errors[] = $this->error(self::CODE_CHAIN_CYCLE);
		}
		if ($flags['depth'])
		{
			$errors[] = $this->error(self::CODE_CHAIN_DEPTH_EXCEEDED);
		}

		return $errors;
	}

	/**
	 * @param array<int, bool> $onPath nodes on the current DFS path, passed by value so each branch
	 *   sees only its own ancestors
	 * @param array{cycle: bool, depth: bool} $flags
	 */
	private function walkChain(int $node, array $onPath, int $depth, array &$flags): void
	{
		if ($depth > DataViewLimitsService::CHAIN_DEPTH)
		{
			$flags['depth'] = true;

			return;
		}

		$onPath[$node] = true;
		foreach ($this->chainEdgesOf($node) as $next)
		{
			if (isset($onPath[$next]))
			{
				$flags['cycle'] = true;

				continue;
			}

			$this->walkChain($next, $onPath, $depth + 1, $flags);
		}
	}

	/**
	 * @return array<int, true> known data view storage type ids as a lookup set
	 */
	private function knownViewTypeIds(): array
	{
		return $this->knownTypeIds ??= array_fill_keys($this->dataViewRepository->getStorageTypeIds(), true);
	}

	/**
	 * @return array{code: string, field: string, message: string}
	 */
	private function error(string $code): array
	{
		return [
			'code' => $code,
			'field' => $this->fieldForCode($code),
			'message' => $this->messageForCode($code),
		];
	}

	private function fieldForCode(string $code): string
	{
		return match ($code)
		{
			self::CODE_SOURCE_UNKNOWN,
			self::CODE_CHAIN_CYCLE,
			self::CODE_CHAIN_DEPTH_EXCEEDED => self::FIELD_SOURCES,
			self::CODE_FIELD_UNKNOWN,
			self::CODE_COLUMN_CODE_DUPLICATE,
			self::CODE_STAMP_INVALID,
			self::CODE_COLUMN_FORMULA_INVALID,
			self::CODE_COLUMN_FORMULA_REFERENCE_INVALID,
			self::CODE_COLUMN_FORMULA_FUNCTION_NOT_ALLOWED,
			self::CODE_COLUMN_TYPE_CHANGED => self::FIELD_COLUMNS,
			self::CODE_JOIN_KEY_TYPE_MISMATCH => self::FIELD_JOIN_KEYS,
			self::CODE_PERIOD_INVALID => self::FIELD_PERIOD,
			self::CODE_AGGREGATE_FUNCTION_NOT_APPLICABLE => self::FIELD_AGGREGATE,
			default => self::FIELD_GENERAL,
		};
	}

	private function messageForCode(string $code): string
	{
		$key = match ($code)
		{
			self::CODE_SOURCE_UNKNOWN => 'BIZPROC_DATAVIEW_VALIDATOR_SOURCE_UNKNOWN',
			self::CODE_FIELD_UNKNOWN => 'BIZPROC_DATAVIEW_VALIDATOR_FIELD_UNKNOWN',
			self::CODE_JOIN_KEY_TYPE_MISMATCH => 'BIZPROC_DATAVIEW_VALIDATOR_JOIN_KEY_TYPE_MISMATCH',
			self::CODE_PERIOD_INVALID => 'BIZPROC_DATAVIEW_VALIDATOR_PERIOD_INVALID',
			self::CODE_AGGREGATE_FUNCTION_NOT_APPLICABLE => 'BIZPROC_DATAVIEW_VALIDATOR_AGGREGATE_FUNCTION_NOT_APPLICABLE',
			self::CODE_COLUMN_CODE_DUPLICATE => 'BIZPROC_DATAVIEW_VALIDATOR_COLUMN_CODE_DUPLICATE',
			self::CODE_STAMP_INVALID => 'BIZPROC_DATAVIEW_VALIDATOR_STAMP_INVALID',
			self::CODE_COLUMN_FORMULA_INVALID => 'BIZPROC_DATAVIEW_VALIDATOR_COLUMN_FORMULA_INVALID',
			self::CODE_COLUMN_FORMULA_REFERENCE_INVALID => 'BIZPROC_DATAVIEW_VALIDATOR_COLUMN_FORMULA_REFERENCE_INVALID',
			self::CODE_COLUMN_FORMULA_FUNCTION_NOT_ALLOWED => 'BIZPROC_DATAVIEW_VALIDATOR_COLUMN_FORMULA_FUNCTION_NOT_ALLOWED',
			self::CODE_COLUMN_TYPE_CHANGED => 'BIZPROC_DATAVIEW_VALIDATOR_COLUMN_TYPE_CHANGED',
			self::CODE_CHAIN_CYCLE => 'BIZPROC_DATAVIEW_VALIDATOR_CHAIN_CYCLE',
			self::CODE_CHAIN_DEPTH_EXCEEDED => 'BIZPROC_DATAVIEW_VALIDATOR_CHAIN_DEPTH_EXCEEDED',
			default => 'BIZPROC_DATAVIEW_VALIDATOR_DEFINITION_INVALID',
		};

		return (string)Loc::getMessage($key);
	}
}
