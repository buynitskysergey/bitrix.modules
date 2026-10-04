<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Infrastructure\Rest\ResponseMapper\Item\ProductRow;

use Bitrix\Crm\Service\Container;
use Bitrix\Crm\V2\Internal\Service\ProductRow\ProductRowErrorCode;
use Bitrix\Main\Error;
use Bitrix\Main\Result;
use Bitrix\Rest\V3\Exception\EntityNotFoundException;
use Bitrix\Rest\V3\Exception\UnknownDtoPropertyException;
use Bitrix\Rest\V3\Exception\Validation\DtoValidationException;
use Bitrix\Rest\V3\Exception\Validation\RequestValidationException;

/**
 * Turns a refused product row scenario into the answer REST v3 gives for it.
 *
 * The mapping is a table over the structured codes the scenario reports and nothing else - no message is
 * ever parsed. Two answers cover every failure of the group:
 *
 * | Scenario code | Answer | Status |
 * |---|---|---|
 * | `CRM_PRODUCT_ROW_NOT_FOUND` | {@see EntityNotFoundException} | 400 |
 * | `CRM_PRODUCT_ROW_ACCESS_DENIED` | {@see EntityNotFoundException} | 400 |
 * | `CRM_PRODUCT_ROW_OWNER_MISMATCH` | {@see EntityNotFoundException} | 400 |
 * | `CRM_PRODUCT_ROW_REQUEST_VALIDATION` | {@see RequestValidationException} | 400 |
 * | `CRM_PRODUCT_ROW_PRODUCT_NOT_FOUND` | {@see RequestValidationException} | 400 |
 * | `CRM_PRODUCT_ROW_CATALOG_ACCESS_DENIED` | {@see RequestValidationException} | 400 |
 * | `CRM_PRODUCT_ROW_SAVE_FAILED` | {@see RequestValidationException} | 400 |
 * | no code at all | {@see RequestValidationException} | 400 |
 *
 * The message of an answer comes from the scenario as well, and it comes untouched - including the one
 * the pipeline that updates the owner refused a save with. That text is how a portal explains to its own
 * integrations why a write was rejected, and the legacy action of the same scenario has always answered
 * with it; withholding it here would only make the new contract the less useful of the two. A refused save
 * is additionally left in the log, see {@see logRefusedSaves()}.
 *
 * ### Why absence and refusal answer alike
 *
 * The scenario tells "there is no such row" and "you may not have it" apart on every path, and it has to:
 * a check that cannot name its reason cannot be reviewed. Outwardly they are one answer, because the
 * difference between them is itself information about a resource the client has no right to - an
 * identifier that answers "forbidden" is an identifier that exists. Collapsing them is therefore the work
 * of this layer and of no other. `OWNER_MISMATCH` collapses with them for exactly the same reason: it
 * says that a row exists under a parent other than the one addressed.
 *
 * The refusal of an action the user may not perform at all would be a
 * {@see \Bitrix\Rest\V3\Exception\AccessDeniedException} - the group has no such refusal, as every right
 * it checks is a right on a named owner.
 *
 * ### Why the codes come from `Internal`
 *
 * {@see ProductRowErrorCode} is internal, and the two codes the read side also speaks are published on
 * {@see \Bitrix\Crm\V2\Public\Provider\Item\ProductRow\ProductRowProvider} while the five write ones are
 * not. Restating the strings here would give the table a second copy to drift from, so this transitional
 * dependency is taken on knowingly and ends when the vocabulary of the group is published in `Public`.
 */
final class ProductRowErrorMapper
{
	/**
	 * The codes that answer as an absent resource. Kept as a list rather than a match arm per code: a
	 * code this layer does not know must answer as a request that failed, never as a resource that is
	 * gone.
	 */
	private const NOT_FOUND_CODES = [
		ProductRowErrorCode::NOT_FOUND,
		ProductRowErrorCode::ACCESS_DENIED,
		ProductRowErrorCode::OWNER_MISMATCH,
	];

	/** @see \Bitrix\Crm\V2\Infrastructure\Rest\Dto\Item\ProductRow\ProductRowDto::$productId */
	private const PRODUCT_ID_FIELD = 'productId';

	/** @see \Bitrix\Crm\V2\Infrastructure\Rest\Request\Item\ProductRow\ReplaceProductRowsRequest::$items */
	private const SET_PROPERTY = 'items';

	/**
	 * @param int $missingEntityId what the client asked for and did not get: the row for the methods
	 *        addressing one, the owner for the methods addressing a whole composition.
	 *
	 * @throws EntityNotFoundException
	 * @throws RequestValidationException
	 */
	public function throwOnFailure(Result $result, int $missingEntityId): void
	{
		if ($result->isSuccess())
		{
			return;
		}

		$errors = $result->getErrors();

		foreach ($errors as $error)
		{
			if (in_array((string)$error->getCode(), self::NOT_FOUND_CODES, true))
			{
				throw new EntityNotFoundException($missingEntityId);
			}
		}

		self::logRefusedSaves($errors);

		throw new RequestValidationException(array_map(self::toValidationError(...), $errors));
	}

	/**
	 * The same refusal of the form of one row of a set, addressed within that set: `id` becomes
	 * `items.3.id`.
	 *
	 * The class of the refusal is kept, so a malformed row of a replacement is answered exactly as the
	 * same malformed field set of a single-row write. Only the address changes, and it has to: the
	 * framework validates a field set without knowing it is one of many, while the key a row came under
	 * is the only thing that tells the client which of them to fix.
	 *
	 * Rebuilding a validation refusal resolves its messages here rather than when the answer is written,
	 * so they are in the default language of the portal. That is the price of the address, and it is
	 * paid only by a request that is refused.
	 *
	 * @throws DtoValidationException
	 * @throws UnknownDtoPropertyException
	 */
	public function throwOnRowFailure(
		DtoValidationException|UnknownDtoPropertyException $exception,
		int|string $rowIndex,
	): never
	{
		if ($exception instanceof UnknownDtoPropertyException)
		{
			throw new UnknownDtoPropertyException(
				$exception->dtoShortName,
				self::getSetAddress($rowIndex, $exception->propertyName),
			);
		}

		$errors = [];
		foreach ($exception->output()['validation'] ?? [] as $validationItem)
		{
			$errors[] = new Error(
				(string)($validationItem['message'] ?? ''),
				self::getSetAddress($rowIndex, $validationItem['field'] ?? null),
			);
		}

		throw new DtoValidationException($errors);
	}

	/**
	 * A scenario error as a validation item: the message untouched, the code replaced by the address of
	 * the value the error is about.
	 *
	 * {@see \Bitrix\Rest\V3\Exception\Validation\ValidationException::output()} publishes the code of an
	 * error as the `field` of the answer, so a code here is an address and nothing else. The address is
	 * the one the scenario reports in the custom data of the error - a property, the row of a set it
	 * belongs to, or both. An error about nothing in particular keeps an empty code and is answered by
	 * its message alone: that is the form the refusal of an owner bound to more than one order has
	 * always had, and it is kept rather than given a code of this layer.
	 */
	private static function toValidationError(Error $error): Error
	{
		$customData = $error->getCustomData();
		$customData = is_array($customData) ? $customData : [];

		$field = $customData[ProductRowErrorCode::CUSTOM_DATA_FIELD] ?? null;
		if ($field === null && isset($customData[ProductRowErrorCode::CUSTOM_DATA_PRODUCT_ID]))
		{
			$field = self::PRODUCT_ID_FIELD;
		}

		$rowIndex = $customData[ProductRowErrorCode::CUSTOM_DATA_ROW_INDEX] ?? null;
		$address = $rowIndex === null
			? (string)($field ?? '')
			: self::getSetAddress($rowIndex, $field)
		;

		return new Error($error->getLocalizableMessage() ?? $error->getMessage(), $address);
	}

	/**
	 * The saves the owner update pipeline refused for a reason of its own, in the log of the module: the
	 * message belongs to whichever handler of the save event wrote it, and a portal reading its own log is
	 * the one who can act on it.
	 *
	 * A pass of its own rather than a step of {@see toValidationError()}: that one converts an error and
	 * says so, and a write hidden inside it would be a write nothing in the signature announces.
	 *
	 * Nothing is written for a refusal the pipeline said nothing of its own about - the group named the
	 * failure itself and there is no text to keep.
	 *
	 * @param Error[] $errors
	 */
	private static function logRefusedSaves(array $errors): void
	{
		foreach ($errors as $error)
		{
			if ((string)$error->getCode() !== ProductRowErrorCode::SAVE_FAILED)
			{
				continue;
			}

			$message = (string)$error->getMessage();
			if ($message === '' || $message === ProductRowErrorCode::getSaveFailedMessage())
			{
				continue;
			}

			Container::getInstance()->getLogger('Default')->info(
				'{class}: the update pipeline refused a product row write: {pipelineMessage}',
				['class' => self::class, 'pipelineMessage' => $message],
			);
		}
	}

	/**
	 * Where a value sits within the set of a replacement, in the notation the framework itself uses for
	 * the elements of a collection: the key the row came under, then the property.
	 */
	private static function getSetAddress(int|string $rowIndex, ?string $field): string
	{
		$address = self::SET_PROPERTY . '.' . $rowIndex;

		return ($field === null || $field === '') ? $address : $address . '.' . $field;
	}
}
