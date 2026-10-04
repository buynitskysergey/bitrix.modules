<?php

namespace Bitrix\Crm\Controller\Item;

use Bitrix\Crm\Controller\Base;
use Bitrix\Crm\Controller\ErrorCode;
use Bitrix\Crm\ProductRowTable;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Service\ProductRow\ProductRowErrorCode;
use Bitrix\Crm\V2\Internal\Service\ProductRow\ProductRowNormalizer;
use Bitrix\Crm\V2\Public\Command\Item\ProductRow\AddCommand;
use Bitrix\Crm\V2\Public\Command\Item\ProductRow\DeleteCommand;
use Bitrix\Crm\V2\Public\Command\Item\ProductRow\ReplaceCommand;
use Bitrix\Crm\V2\Public\Command\Item\ProductRow\UpdateCommand;
use Bitrix\Crm\V2\Public\Command\Item\Scope;
use Bitrix\Crm\V2\Public\Entity\Item\ProductRowCollection;
use Bitrix\Crm\V2\Public\EntityType;
use Bitrix\Crm\V2\Public\Provider\Item\ProductRow\Param\ProductRowFilter;
use Bitrix\Crm\V2\Public\Provider\Item\ProductRow\ProductRowProvider;
use Bitrix\Main\Engine\AutoWire\ExactParameter;
use Bitrix\Main\Engine\Response\Converter;
use Bitrix\Main\Engine\Response\DataType\Page;
use Bitrix\Main\Error;
use Bitrix\Main\ORM\Fields\FieldTypeMask;
use Bitrix\Main\ORM\Objectify\Values;
use Bitrix\Main\Result;
use Bitrix\Main\UI\PageNavigation;
use Bitrix\Main\Loader;
use Bitrix\Catalog;

class ProductRow extends Base
{
	private const ALLOWED_FILTER_FIELDS = ['ID', 'OWNER_ID', 'OWNER_TYPE', 'PRODUCT_ID'];
	private const ALLOWED_ORDER_FIELDS = ['ID', 'SORT'];
	/** longest prefixes go first: a filter key is split by the first matching one, no prefix is allowed too */
	private const ALLOWED_FILTER_OPERATIONS = ['!=', '>=', '<=', '=', '!', '>', '<', '@'];
	private const ALLOWED_ORDER_DIRECTIONS = ['ASC', 'DESC'];
	private const DEFAULT_ORDER = ['SORT' => 'ASC', 'ID' => 'ASC'];

	/**
	 * The fields this group has always let a client write, as the scenario names them: the storage
	 * column its whitelist speaks of => the public field of the scenario.
	 *
	 * Three writable columns are absent on purpose - the published type has no counterpart for them.
	 * Both owner fields are the address of the row for the scenario, and this group has only ever
	 * accepted them to have the normalization of the item overwrite them again; the manual edit flag
	 * the type does not carry at all.
	 *
	 * @see self::toScenarioFields()
	 */
	private const SCENARIO_FIELD_NAMES = [
		'PRODUCT_ID' => 'productId',
		'PRODUCT_NAME' => 'name',
		'PRICE' => 'price',
		'QUANTITY' => 'quantity',
		'DISCOUNT_TYPE_ID' => 'discountTypeId',
		'DISCOUNT_RATE' => 'discountRate',
		'DISCOUNT_SUM' => 'discount',
		'TAX_RATE' => 'taxRate',
		'TAX_NAME' => 'taxName',
		'TAX_INCLUDED' => 'taxIncluded',
		'MEASURE_CODE' => 'measureCode',
		'MEASURE_NAME' => 'measureName',
		'SORT' => 'sort',
	];

	private const SCENARIO_FIELD_TAX_RATE = 'taxRate';

	/** The fields the pipeline writes by itself, so an answered row carries them however it was asked for */
	private const ALWAYS_ANSWERED_COLUMNS = ['ID' => null, 'OWNER_ID' => null, 'OWNER_TYPE' => null];

	/** @var string|ProductRowTable */
	protected $dataManager = ProductRowTable::class;

	public function getAutoWiredParameters(): array
	{
		$params = parent::getAutoWiredParameters();

		$params[] = new ExactParameter(
			\Bitrix\Crm\ProductRow::class,
			'productRow',
			function ($className, array $fields): \Bitrix\Crm\ProductRow {

				$fields = $this->convertKeysToUpper($fields);
				$fields = current($this->prepareForSave([$fields])) ?: [];

				return \Bitrix\Crm\ProductRow::createFromArray($fields);
			}
		);

		return $params;
	}

	public function addAction(\Bitrix\Crm\ProductRow $productRow): ?array
	{
		// the owner of an added row is named by the fields of the row, as it always has been
		$ownerEntityType = $this->resolveSupportedOwnerEntityType((string)$productRow->getOwnerType());
		if ($ownerEntityType === null)
		{
			return null;
		}

		$addResult = (new AddCommand(
			$ownerEntityType,
			(int)$productRow->getOwnerId(),
			self::toScenarioFieldsOfBoundRow($productRow),
			$this->getAccessUserId(),
		))
			->setScope($this->getCommandScope())
			->run()
		;
		if (!$addResult->isSuccess())
		{
			// the owner is addressed by the fields of the row, so an absence is the absence of the owner
			$this->addScenarioErrors($addResult, ErrorCode::getOwnerNotFoundError());

			return null;
		}

		return [
			'productRow' => $this->getWrittenRowInTheAnsweredForm(
				$productRow,
				(int)$addResult->getData()[AddCommand::DATA_PRODUCT_ROW]->getId(),
			),
		];
	}

	/**
	 * The user every scenario of this group runs for: the user of the CRM context - the very user the
	 * permission facade resolved for this group before the switch.
	 */
	protected function getAccessUserId(): int
	{
		return (int)Container::getInstance()->getContext()->getUserId();
	}

	/**
	 * The scope every write scenario of this group runs in. The pipeline of the owner has always been
	 * launched within the context of the request, and the REST scope of that context is set by the
	 * prefilter of this controller from this very answer.
	 *
	 * @see \Bitrix\Crm\Controller\Base::getDefaultPreFilters()
	 */
	protected function getCommandScope(): Scope
	{
		return $this->getScope() === self::SCOPE_REST ? Scope::Rest : Scope::Manual;
	}

	/**
	 * The scenario the group runs on. The subject of every right it checks is the user of the CRM
	 * context - the very user the permission facade resolved for this group before the switch.
	 */
	protected function getProductRowProvider(): ProductRowProvider
	{
		return new ProductRowProvider($this->getAccessUserId());
	}

	/**
	 * The owner type behind the abbreviation of the input, or null for an abbreviation that names no
	 * type this group works with. Silent on purpose: the answer for an unsupported type differs
	 * between the actions, so the caller adds its own error.
	 *
	 * A type this group works with is one that both belongs to the item kinds of the scenario and
	 * exists on this portal. The second half is not redundant: the abbreviation of a smart process
	 * whose type has been deleted still resolves into the range of valid identifiers, and this group
	 * has always answered such a type as unsupported - while the scenario has no answer for it at all,
	 * its entry point being a factory that is not there.
	 *
	 * @see self::resolveSupportedOwnerEntityType() for the actions that answer ENTITY_TYPE_NOT_SUPPORTED
	 */
	protected static function resolveOwnerEntityType(string $ownerType): ?EntityType
	{
		$entityTypeId = \CCrmOwnerTypeAbbr::ResolveTypeID($ownerType);
		if (!EntityType::isValid($entityTypeId) || Container::getInstance()->getFactory($entityTypeId) === null)
		{
			return null;
		}

		return EntityType::fromId($entityTypeId);
	}

	/**
	 * The same resolution with the error this group has always answered for a type it does not work
	 * with, the resolved identifier included in the custom data.
	 */
	protected function resolveSupportedOwnerEntityType(string $ownerType): ?EntityType
	{
		$ownerEntityType = self::resolveOwnerEntityType($ownerType);
		if ($ownerEntityType === null)
		{
			$this->addError(
				ErrorCode::getEntityTypeNotSupportedError(\CCrmOwnerTypeAbbr::ResolveTypeID($ownerType))
			);
		}

		return $ownerEntityType;
	}

	/**
	 * The refusals of a V2 scenario in the form this group has always answered with.
	 *
	 * The answer is chosen by the structured code of the scenario and never by its message. The order of
	 * the errors is kept: the REST transport publishes the first one, so it decides what the client sees.
	 *
	 * The scenario reports an absent row and an absent owner under one and the same code - it is the
	 * transport that decides how much of a cause to disclose - so the caller names the legacy error its
	 * own absence means. Without one it is the absence of the addressed row.
	 */
	protected function addScenarioErrors(Result $result, ?Error $absenceError = null): void
	{
		$absenceError ??= ErrorCode::getNotFoundError();

		foreach ($result->getErrors() as $error)
		{
			$this->addError($this->getLegacyError($error, $absenceError));
		}
	}

	/**
	 * A single refusal of the scenario in the legacy form. An error whose code is not in the table is
	 * answered as it is: the codes the scenario passes through untouched - normalization above all -
	 * keep reaching the client exactly as before.
	 */
	private function getLegacyError(Error $error, Error $absenceError): Error
	{
		return match ((string)$error->getCode())
		{
			ProductRowErrorCode::NOT_FOUND => $absenceError,
			ProductRowErrorCode::ACCESS_DENIED => ErrorCode::getAccessDeniedError(),
			// the codeless refusals of the pipeline: the catalog rights on the price and on the discount,
			// and a row that is not bound to the item it is addressed within
			ProductRowErrorCode::OWNER_MISMATCH,
			ProductRowErrorCode::CATALOG_ACCESS_DENIED,
			ProductRowErrorCode::SAVE_FAILED => new Error(
				$error->getMessage(),
				customData: $error->getCustomData(),
			),
			ProductRowErrorCode::REQUEST_VALIDATION,
			ProductRowErrorCode::PRODUCT_NOT_FOUND => new Error(
				$error->getMessage(),
				ErrorCode::INVALID_ARG_VALUE,
				$error->getCustomData(),
			),
			default => $error,
		};
	}

	/**
	 * The writable part of an input as the whitelist of this group has always decided it: the keys are
	 * converted the way this group converts them - a name that is not camelCase is mangled on the way
	 * and therefore drops out - and then weighed against the very description the help action publishes.
	 *
	 * Only the choice of keys is made here. The values are handed over untouched: the rules that turn a
	 * value into what is stored - the tax rate, the name and the type of the position - live in the
	 * scenario and run there once.
	 *
	 * @return array<string, mixed> storage column names of the fields that may be written
	 */
	protected function acceptWritableFields(array $fields, array $fieldsInfo): array
	{
		return $this->internalizeFields($this->convertKeysToUpper($fields), $fieldsInfo);
	}

	/**
	 * The accepted fields as the scenario names them. A field this group accepts and the published type
	 * does not carry reaches no scenario, so an input of nothing but such fields leaves nothing to write
	 * - which is the answer of this group to it as well.
	 *
	 * @param array<string, mixed> $writableFields
	 *
	 * @return array<string, mixed>
	 */
	protected static function toScenarioFields(array $writableFields): array
	{
		$scenarioFields = [];

		foreach (self::SCENARIO_FIELD_NAMES as $column => $fieldName)
		{
			if (array_key_exists($column, $writableFields))
			{
				$scenarioFields[$fieldName] = $writableFields[$column];
			}
		}

		return $scenarioFields;
	}

	/**
	 * The fields of a row the wired argument has already built, as the scenario names them.
	 *
	 * The whitelist has run for this action while the argument was bound, and so has the tax rate rule:
	 * the object carries a rate that rule already decided on. The scenario applies the rule to whatever
	 * it is given, so the decided rate is handed over in the input form the rule maps back to it - the
	 * integer zero is the only input it answers with a zero rate, while the float zero it answers with
	 * no rate at all.
	 *
	 * @see self::getAutoWiredParameters()
	 *
	 * @return array<string, mixed>
	 */
	private static function toScenarioFieldsOfBoundRow(\Bitrix\Crm\ProductRow $productRow): array
	{
		$scenarioFields = self::toScenarioFields(self::collectScalarValues($productRow));

		if (($scenarioFields[self::SCENARIO_FIELD_TAX_RATE] ?? null) === 0.0)
		{
			$scenarioFields[self::SCENARIO_FIELD_TAX_RATE] = 0;
		}

		return $scenarioFields;
	}

	/**
	 * The written row in the form this action has always answered with: the row the fields of the client
	 * built, carrying the values the pipeline left in the storage.
	 *
	 * The answer is assembled rather than simply read back, because the two forms differ in one field: a
	 * rate the client never sent is not part of the row this action answers, while a stored row always
	 * carries it. Everything the pipeline writes by itself - the identifier, the binding to the owner
	 * and the derived prices - is answered all the same.
	 */
	private function getWrittenRowInTheAnsweredForm(
		\Bitrix\Crm\ProductRow $requestedRow,
		int $rowId,
	): ?\Bitrix\Crm\ProductRow
	{
		$storedRow = $this->dataManager::getById($rowId)->fetchObject();
		if (!$storedRow)
		{
			return null;
		}

		return \Bitrix\Crm\ProductRow::createFromArray(
			array_intersect_key(
				self::collectScalarValues($storedRow),
				self::collectScalarValues($requestedRow) + self::ALWAYS_ANSWERED_COLUMNS,
			),
		);
	}

	/**
	 * @return array<string, mixed>
	 */
	private static function collectScalarValues(\Bitrix\Crm\ProductRow $productRow): array
	{
		return $productRow->collectValues(Values::ALL, FieldTypeMask::SCALAR);
	}

	public function getAction(int $id): ?array
	{
		$productRow = $this->dataManager::getByPrimary(
			$id,
			['select' => ['*', 'PRODUCT_ROW_RESERVATION.STORE_ID']],
		)->fetchObject();
		if (!$productRow)
		{
			$this->addError(
				ErrorCode::getNotFoundError()
			);

			return null;
		}

		if ($this->resolveSupportedOwnerEntityType((string)$productRow->getOwnerType()) === null)
		{
			return null;
		}

		$readResult = $this->getProductRowProvider()->getById($id);
		if (!$readResult->isSuccess())
		{
			// the row itself is right here, so an absence the scenario reports is the absence of its owner
			$this->addScenarioErrors($readResult, ErrorCode::getOwnerNotFoundError());

			return null;
		}

		return [
			'productRow' => $this->getFormattedRow($productRow),
		];
	}

	public function deleteAction(int $id): void
	{
		$productRow = $this->dataManager::getById($id)->fetchObject();
		if (!$productRow)
		{
			$this->addError(
				ErrorCode::getNotFoundError()
			);

			return;
		}

		$ownerEntityType = $this->resolveSupportedOwnerEntityType((string)$productRow->getOwnerType());
		if ($ownerEntityType === null)
		{
			return;
		}

		$deleteResult = (new DeleteCommand($ownerEntityType, $id, $this->getAccessUserId()))
			->setScope($this->getCommandScope())
			->run()
		;
		if (!$deleteResult->isSuccess())
		{
			// the row itself is right here, so an absence the scenario reports is the absence of its owner
			$this->addScenarioErrors($deleteResult, ErrorCode::getOwnerNotFoundError());
		}
	}

	public function updateAction(int $id, array $fields): ?array
	{
		$originalProductRow = $this->dataManager::getById($id)->fetchObject();
		if (!$originalProductRow)
		{
			$this->addError(
				ErrorCode::getNotFoundError()
			);

			return null;
		}

		$ownerEntityType = $this->resolveSupportedOwnerEntityType((string)$originalProductRow->getOwnerType());
		if ($ownerEntityType === null)
		{
			return null;
		}

		$writableFields = $this->acceptWritableFields($fields, $this->getFieldsInfo());
		$scenarioFields = self::toScenarioFields($writableFields);

		$updateResult = (new UpdateCommand($ownerEntityType, $id, $scenarioFields, $this->getAccessUserId()))
			->setScope($this->getCommandScope())
			->run()
		;
		if (!$updateResult->isSuccess())
		{
			if (!self::isNothingToWrite($updateResult, $scenarioFields))
			{
				// the row itself is right here, so an absence the scenario reports is the absence of its owner
				$this->addScenarioErrors($updateResult, ErrorCode::getOwnerNotFoundError());

				return null;
			}

			// an input the whitelist emptied is refused; one that kept a field the scenario has no name
			// for leaves the row exactly as it was, the way the overwritten owner fields always have
			if ($writableFields === [])
			{
				$this->addUnwritableInputErrors($fields);

				return null;
			}
		}

		return [
			'productRow' => $this->dataManager::getById($id)->fetchObject(),
		];
	}

	/**
	 * Whether the scenario refused the request for the one reason this group answers by itself: it was
	 * given nothing to write. The owner and the right are checked before that and inside the scenario,
	 * so a refusal of either is never mistaken for an empty input.
	 *
	 * @param array<string, mixed> $scenarioFields
	 */
	private static function isNothingToWrite(Result $result, array $scenarioFields): bool
	{
		$errors = $result->getErrors();
		$firstError = reset($errors);

		return
			$scenarioFields === []
			&& $firstError instanceof Error
			&& (string)$firstError->getCode() === ProductRowErrorCode::REQUEST_VALIDATION
		;
	}

	/**
	 * The refusal this group has always answered an input of nothing but unwritable keys with: one error
	 * per key of the input, in the order of the input, naming the key exactly as the client sent it. A
	 * single writable field among them makes the rest disappear in silence instead.
	 */
	private function addUnwritableInputErrors(array $fields): void
	{
		foreach (array_keys($fields) as $fieldName)
		{
			$this->addError(
				new Error("Field '{$fieldName}' not available for update", ErrorCode::INVALID_ARG_VALUE),
			);
		}
	}

	public function listAction(?array $order = null, ?array $filter = null, ?PageNavigation $pageNavigation = null): ?Page
	{
		$getListParams = $this->prepareGetListParamsFromArgs($order, $filter, $pageNavigation);

		if (!$this->checkReadPermissions($getListParams))
		{
			return null;
		}

		if (!$this->isGetListParamsValid($getListParams))
		{
			return null;
		}

		// the owner condition is already checked, so from now on the query works with whitelisted keys only
		$getListParams['filter'] = $this->buildSafeFilter($getListParams['filter']);

		$formattedProductRows = [];
		$collection = $this->dataManager::getList($getListParams)->fetchCollection();
		foreach ($collection as $collectionElement)
		{
			$formattedProductRows[] = $this->getFormattedRow($collectionElement);
		}

		return new Page(
			'productRows',
			$formattedProductRows,
			function () use ($getListParams): int {
				return $this->dataManager::getCount($getListParams['filter']);
			}
		);
	}

	private function getFormattedRow(\Bitrix\Crm\ProductRow $collectionElement): array
	{
		$formattedProductRow = $collectionElement->jsonSerialize();
		$formattedProductRow['storeId'] ??= null;
		unset(
			$formattedProductRow['reserveId'],
			$formattedProductRow['reserveQuantity'],
			$formattedProductRow['dateReserveEnd'],
			$formattedProductRow['isAuto'],
		);

		return $formattedProductRow;
	}

	protected function prepareGetListParamsFromArgs(?array $order, ?array $filter, ?PageNavigation $pageNavigation): array
	{
		$params = [
			'select' => [
				'*',
				'PRODUCT_ROW_RESERVATION.STORE_ID',
			],
			'order' => $this->prepareOrderFromArgs($order),
			'filter' => $this->prepareFilterFromArgs($filter),
		];

		if ($pageNavigation)
		{
			$params['offset'] = $pageNavigation->getOffset();
			$params['limit'] = $pageNavigation->getLimit();
		}

		return $params;
	}

	protected function prepareOrderFromArgs(?array $order): array
	{
		if (!is_array($order))
		{
			$order = [];
		}

		return $this->buildSafeOrder($this->convertKeysToUpper($order));
	}

	protected function prepareFilterFromArgs(?array $filter): array
	{
		if (!is_array($filter))
		{
			$filter = [];
		}

		$filter = $this->convertKeysToUpper($filter);

		return $this->removeDotsFromKeys($filter);
	}

	protected function checkReadPermissions(array $getListParams): bool
	{
		$ownerType = $getListParams['filter']['=OWNER_TYPE'] ?? null;
		if (!is_scalar($ownerType) || empty($ownerType))
		{
			$this->addError(
				ErrorCode::getRequiredArgumentMissingError('=ownerType')
			);

			return false;
		}

		$ownerId = $getListParams['filter']['=OWNER_ID'] ?? null;
		// permissions are checked against a single owner, therefore a list of owners is not an owner id
		if (!$this->isSingleOwnerId($ownerId))
		{
			$this->addError(
				ErrorCode::getRequiredArgumentMissingError('=ownerId')
			);

			return false;
		}

		$ownerEntityType = self::resolveOwnerEntityType((string)$ownerType);
		if ($ownerEntityType === null)
		{
			// an unresolved abbreviation has never been a missing argument here: the list denies access
			$this->addError(
				ErrorCode::getAccessDeniedError()
			);

			return false;
		}

		// the rights of this group belong to the owner, and the scenario applies them as a condition of
		// the read. An empty set of identifiers matches no row by contract, so the owner is checked
		// without reading rows this action reads for itself.
		$accessResult = $this->getProductRowProvider()->getList(
			$ownerEntityType,
			new ProductRowFilter((int)$ownerId, ids: []),
		);
		if (!$accessResult->isSuccess())
		{
			// the list has never told an unreadable owner from an absent one
			$this->addScenarioErrors($accessResult, ErrorCode::getAccessDeniedError());

			return false;
		}

		return true;
	}

	protected function isGetListParamsValid(array $getListParams): bool
	{
		return $this->isFilterValid($getListParams['filter'] ?? []);
	}

	/**
	 * Rejects values that this method rejected before the filter whitelist was introduced.
	 * The selection itself is protected by buildSafeFilter(), this check only keeps INVALID_ARG_VALUE
	 * in the answer for the clients that relied on it.
	 *
	 * @see self::buildSafeFilter()
	 */
	protected function isFilterValid(array $filter): bool
	{
		$isErrorProneOperation = static function(string $key): bool {
			return (
				preg_match('/([<>])/u', $key)
				&& mb_strpos($key, '><') === false
			);
		};
		$isValidType = fn($value) => is_string($value) || is_numeric($value);

		foreach ($filter as $key => $value)
		{
			if (is_string($key) && $isErrorProneOperation($key) && !$isValidType($value))
			{
				$this->addError(
					new Error("Value for filter key '{$key}' should be either string or number", ErrorCode::INVALID_ARG_VALUE),
				);

				return false;
			}

			if (is_array($value) && !$this->isFilterValid($value))
			{
				return false;
			}
		}

		return true;
	}

	private function isSingleOwnerId(mixed $ownerId): bool
	{
		return
			is_scalar($ownerId)
			&& filter_var($ownerId, FILTER_VALIDATE_INT) !== false
			&& (int)$ownerId > 0
		;
	}

	/**
	 * Builds the filter of the selection from the whitelisted keys of the user filter. The owner condition
	 * is set here and only here, so it is always an unconditional AND and never a summand of the user logic.
	 */
	private function buildSafeFilter(array $filter): array
	{
		$safeFilter = [
			'=OWNER_TYPE' => $filter['=OWNER_TYPE'],
			'=OWNER_ID' => (int)$filter['=OWNER_ID'],
		];

		foreach ($filter as $key => $value)
		{
			if (!is_string($key))
			{
				continue;
			}

			[$operation, $fieldName] = $this->splitFilterKey($key);
			if (
				!in_array($fieldName, self::ALLOWED_FILTER_FIELDS, true)
				// the owner is already set above and can not be redefined by the user filter
				|| in_array($fieldName, ['OWNER_TYPE', 'OWNER_ID'], true)
			)
			{
				continue;
			}

			if ($operation === '@')
			{
				if (is_array($value) && !empty($value))
				{
					$safeFilter[$key] = $value;
				}

				continue;
			}

			if (is_scalar($value))
			{
				$safeFilter[$key] = $value;
			}
		}

		return $safeFilter;
	}

	/**
	 * @return array{0: string, 1: string} operation and field name. An unsupported operation stays in the
	 * field name, so such a key does not match the whitelist and is dropped by the caller
	 */
	private function splitFilterKey(string $key): array
	{
		foreach (self::ALLOWED_FILTER_OPERATIONS as $operation)
		{
			if (str_starts_with($key, $operation))
			{
				return [$operation, mb_substr($key, mb_strlen($operation))];
			}
		}

		return ['', $key];
	}

	private function buildSafeOrder(array $order): array
	{
		$safeOrder = [];

		foreach ($order as $fieldName => $direction)
		{
			if (!is_string($fieldName) || !is_scalar($direction))
			{
				continue;
			}

			$fieldName = mb_strtoupper($fieldName);
			$direction = mb_strtoupper((string)$direction);

			if (
				in_array($fieldName, self::ALLOWED_ORDER_FIELDS, true)
				&& in_array($direction, self::ALLOWED_ORDER_DIRECTIONS, true)
			)
			{
				$safeOrder[$fieldName] = $direction;
			}
		}

		return $safeOrder ?: self::DEFAULT_ORDER;
	}

	public function setAction(string $ownerType, int $ownerId, array $productRows): ?array
	{
		$ownerEntityType = $this->resolveSupportedOwnerEntityType($ownerType);
		if ($ownerEntityType === null)
		{
			return null;
		}

		$setResult = (new ReplaceCommand(
			$ownerEntityType,
			$ownerId,
			$this->toScenarioRows($productRows),
			$this->getAccessUserId(),
		))
			->setScope($this->getCommandScope())
			->run()
		;
		if (!$setResult->isSuccess())
		{
			// the owner is addressed by the arguments of the action, so an absence is the absence of the owner
			$this->addScenarioErrors($setResult, ErrorCode::getOwnerNotFoundError());

			return null;
		}

		return [
			'productRows' => $this->getStoredRowsOfOwner($ownerEntityType, $ownerId),
		];
	}

	/**
	 * The rows of the input as the scenario names them, under the keys they came with.
	 *
	 * A row the whitelist left without a single writable field falls out of the set without a word,
	 * exactly as it always has - and since the replacement removes whatever was not sent, that silence
	 * is how such a row gets deleted.
	 *
	 * @return array<int|string, array<string, mixed>>
	 */
	private function toScenarioRows(array $productRows): array
	{
		$fieldsInfo = $this->getFieldsInfo();

		$scenarioRows = [];
		foreach ($productRows as $index => $productRow)
		{
			$writableFields = $this->acceptWritableFields($productRow, $fieldsInfo);
			if ($writableFields === [])
			{
				continue;
			}

			$scenarioRows[$index] = self::toScenarioFields($writableFields);
		}

		return $scenarioRows;
	}

	/**
	 * The composition of the owner in the form this action has always answered with: the stored rows
	 * themselves, the whole set of the item rather than the rows of the input.
	 *
	 * @return \Bitrix\Crm\ProductRow[]
	 */
	private function getStoredRowsOfOwner(EntityType $ownerEntityType, int $ownerId): array
	{
		return $this->dataManager::getList([
			'select' => ['*'],
			'filter' => [
				'=OWNER_TYPE' => \CCrmOwnerTypeAbbr::ResolveByTypeID($ownerEntityType->getId()),
				'=OWNER_ID' => $ownerId,
			],
			'order' => self::DEFAULT_ORDER,
		])->fetchCollection()->getAll();
	}

	public function fieldsAction(): array
	{
		$fieldsInfo = $this->getFieldsInfo();
		$fieldsInfoInRestFormat = \CCrmRestHelper::prepareFieldInfos($fieldsInfo);

		// intentionally not recursive to preserve keys like 'isRequired'
		$converter = new Converter(Converter::KEYS | Converter::TO_CAMEL | Converter::LC_FIRST);

		return [
			'fields' => $converter->process($fieldsInfoInRestFormat)
		];
	}

	protected function getFieldsInfo(): array
	{
		$fieldsInfo = \CCrmProductRow::GetFieldsInfo();
		$fieldsInfo['STORE_ID'] = [
			'TYPE' => 'integer',
			'ATTRIBUTES' => [\CCrmFieldInfoAttr::ReadOnly],
		];

		foreach ($fieldsInfo as $fieldName => &$singleFieldInfo)
		{
			$singleFieldInfo['CAPTION'] = \CCrmProductRow::GetFieldCaption($fieldName);

			// some fields are read-only in new api, but fully changeable in old api
			// we can't simply modify \CCrmProductRow::GetFieldsInfo() as we have 2 different cases
			// therefore, add read-only attribute to new api fields in runtime
			if (
				!\CCrmFieldInfoAttr::isFieldReadOnly($singleFieldInfo)
				&& in_array($fieldName, $this->getReadOnlyFieldNames(), true)
			)
			{
				$singleFieldInfo['ATTRIBUTES'][] = \CCrmFieldInfoAttr::ReadOnly;
			}
		}

		return $fieldsInfo;
	}

	/**
	 * @return string[]
	 */
	protected function getReadOnlyFieldNames(): array
	{
		return [
			'PRICE_EXCLUSIVE',
			'PRICE_NETTO',
			'PRICE_BRUTTO',
			'PRICE_ACCOUNT',
		];
	}

	/**
	 * Prepares product fields for save:
	 * Checks attributes
	 * Calculates TYPE and PRODUCT_NAME
	 *
	 * The tax rate is decided by the rule of the scenario ({@see ProductRowNormalizer::resolveTaxRate()})
	 * rather than by a copy of it: the scenario applies the same rule to whatever it is given, and two
	 * copies of one rule would sooner or later answer differently.
	 *
	 * @param $productRows
	 * @return array
	 */
	protected function prepareForSave($productRows): array
	{
		$result = [];
		$fieldsInfo = $this->getFieldsInfo();

		foreach ($productRows as $index => $productRow)
		{
			$internalizedFields = $this->internalizeFields($productRow, $fieldsInfo);
			if ($internalizedFields)
			{
				$result[$index] = $internalizedFields;
			}

			if (isset($productRow['TAX_RATE']))
			{
				$result[$index]['TAX_RATE'] = ProductRowNormalizer::resolveTaxRate($productRow['TAX_RATE']);
			}
		}

		$productIds = array_filter(array_column($result, 'PRODUCT_ID'));
		if ($productIds && Loader::includeModule('catalog'))
		{
			$productData = [];

			$productTableIterator = Catalog\ProductTable::getList([
				'select' => [
					'ID',
					'PRODUCT_NAME' => 'IBLOCK_ELEMENT.NAME',
					'TYPE',
				],
				'filter' => [
					'@ID' => $productIds,
				],
			]);
			while ($productItem = $productTableIterator->fetch())
			{
				$productData[$productItem['ID']] = $productItem;
			}

			foreach ($result as $index => $productRow)
			{
				$productId = $productRow['PRODUCT_ID'] ?? null;

				if ($productId && isset($productData[$productId]))
				{
					$result[$index]['TYPE'] = (int)$productData[$productId]['TYPE'];
					$result[$index]['PRODUCT_NAME'] ??= $productData[$productId]['PRODUCT_NAME'];
				}
			}
		}

		return $result;
	}

	/**
	 * Removes from $fields ReadOnly and Hidden values
	 *
	 * @param array $fields
	 * @param array $fieldsInfo
	 * @return array
	 */
	protected function internalizeFields(array $fields, array $fieldsInfo): array
	{
		$result = [];

		$ignoredAttributes = [
			\CCrmFieldInfoAttr::ReadOnly,
			\CCrmFieldInfoAttr::Hidden
		];

		foreach ($fields as $fieldName => $fieldsValue)
		{
			$info = $fieldsInfo[$fieldName] ?? null;
			if (!$info)
			{
				unset($fields[$fieldName]);
				continue;
			}

			$attrs = $info['ATTRIBUTES'] ?? [];

			$attrs = array_intersect($ignoredAttributes, $attrs);
			if (!empty($attrs))
			{
				unset($fields[$fieldName]);
				continue;
			}

			$result[$fieldName] = $fieldsValue;
		}

		return $result;
	}

	public function getAvailableForPaymentAction(int $ownerId, string $ownerType): ?array
	{
		$ownerEntityType = $this->resolveSupportedOwnerEntityType($ownerType);
		if ($ownerEntityType === null)
		{
			return null;
		}

		$payableResult = $this->getProductRowProvider()->getAvailableForPayment($ownerEntityType, $ownerId);
		if (!$payableResult->isSuccess())
		{
			// the owner is addressed by the arguments of the action, so an absence is the absence of the owner
			$this->addScenarioErrors($payableResult, ErrorCode::getOwnerNotFoundError());

			return null;
		}

		return [
			'productRows' => $this->getStoredRowsWithPayableQuantity(
				$payableResult->getData()[ProductRowProvider::DATA_PRODUCT_ROWS],
			),
		];
	}

	/**
	 * The payable rows in the form this action has always answered with: the stored rows themselves,
	 * with the payable quantity put in place of the stored one and nothing saved.
	 *
	 * Which rows are payable and how much of each is the scenario's answer; the stored rows are read
	 * here because the answer of this group carries the whole row - the derived prices, the manual edit
	 * flag, the external code and the reserve - and the published type of the scenario carries none of
	 * them.
	 *
	 * @return \Bitrix\Crm\ProductRow[]
	 */
	private function getStoredRowsWithPayableQuantity(ProductRowCollection $payableRows): array
	{
		$quantityByRowId = [];
		foreach ($payableRows->getAll() as $payableRow)
		{
			$quantityByRowId[(int)$payableRow->getId()] = $payableRow->getQuantity();
		}

		if (!$quantityByRowId)
		{
			return [];
		}

		$result = [];
		$storedRows = $this->dataManager::getList([
			// the reserve is read the way the item read it, so a reserved row keeps answering its reserve
			'select' => ['*', 'PRODUCT_ROW_RESERVATION'],
			'filter' => ['@ID' => array_keys($quantityByRowId)],
			'order' => self::DEFAULT_ORDER,
		])->fetchCollection();

		foreach ($storedRows as $storedRow)
		{
			$result[] = $storedRow->setQuantity($quantityByRowId[$storedRow->getId()]);
		}

		return $result;
	}
}
