<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Service\ProductRow;

use Bitrix\Crm\Integration\Catalog\Access\ProductRowChecker;
use Bitrix\Crm\V2\Public\Provider\Item\ProductRow\ProductRowProvider;
use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;

/**
 * The structured error codes of the product row write scenarios, and the single place their errors
 * are built.
 *
 * Every failure that leaves a product row handler carries one of these codes: the transport maps
 * codes to answers by one table and never parses messages. The read side is the same vocabulary -
 * {@see NOT_FOUND} and {@see ACCESS_DENIED} are literally the constants of
 * {@see ProductRowProvider}, so reading and writing cannot drift apart. Their wording comes from there
 * as well and is not localized: no contract of this group ever shows it, see
 * {@see ProductRowProvider::MESSAGE_NOT_FOUND}. Every message a client does see is a phrase.
 *
 * Errors coming out of the owner update pipeline are never rewritten in meaning: their message is
 * kept as it is and only the code is attached, see {@see fromPipelineError()}. Callers that answer
 * a contract of their own decide whether such a message may be shown - the message of a codeless
 * refusal belongs to whoever wrote the handler of the save event, not to this group, and
 * {@see getSaveFailedMessage()} is what the group itself calls that failure.
 *
 * @internal
 */
final class ProductRowErrorCode
{
	/** The row, or the owner it is written within, does not exist. */
	public const NOT_FOUND = ProductRowProvider::ERROR_NOT_FOUND;

	/** The owner exists, but the access user may not update it. */
	public const ACCESS_DENIED = ProductRowProvider::ERROR_ACCESS_DENIED;

	/**
	 * The row exists, but under another owner than the one it was addressed through. Externally it
	 * is the same answer as {@see NOT_FOUND} - the addressed resource is not there - but the layer
	 * keeps the two causes apart.
	 */
	public const OWNER_MISMATCH = 'CRM_PRODUCT_ROW_OWNER_MISMATCH';

	/** The request is malformed: an unknown or read-only field, or nothing writable at all. */
	public const REQUEST_VALIDATION = 'CRM_PRODUCT_ROW_REQUEST_VALIDATION';

	/** The catalog does not know the product the row points at. */
	public const PRODUCT_NOT_FOUND = 'CRM_PRODUCT_ROW_PRODUCT_NOT_FOUND';

	/** Catalog rights forbid the price or the discount the row carries. */
	public const CATALOG_ACCESS_DENIED = 'CRM_PRODUCT_ROW_CATALOG_ACCESS_DENIED';

	/** The owner update pipeline refused the change for a reason of its own. */
	public const SAVE_FAILED = 'CRM_PRODUCT_ROW_SAVE_FAILED';

	/** Custom data key: the public name of the field an error is about. */
	public const CUSTOM_DATA_FIELD = 'field';

	/** Custom data key: the index of the offending row within a set. */
	public const CUSTOM_DATA_ROW_INDEX = 'rowIndex';

	/** Custom data key: the product id an error is about. */
	public const CUSTOM_DATA_PRODUCT_ID = 'productId';

	public static function notFound(): Error
	{
		return new Error(ProductRowProvider::MESSAGE_NOT_FOUND, self::NOT_FOUND);
	}

	public static function accessDenied(): Error
	{
		return new Error(ProductRowProvider::MESSAGE_ACCESS_DENIED, self::ACCESS_DENIED);
	}

	public static function ownerMismatch(): Error
	{
		return new Error('The product row belongs to another owner', self::OWNER_MISMATCH);
	}

	/**
	 * Not localized, unlike the other refusals of the whitelist: no contract of this group can reach it.
	 * The new REST answers an unknown property from the DTO of the framework long before a field set gets
	 * here, and the legacy facade drops a name its own whitelist does not know. What is left is a PHP
	 * caller of the public commands, and an internal string is what it gets - the same choice
	 * {@see ProductRowProvider::MESSAGE_NOT_FOUND} makes for the same reason.
	 */
	public static function unknownField(string $field, int|string|null $rowIndex = null): Error
	{
		return new Error(
			sprintf('Unknown product row field: "%s"', $field),
			self::REQUEST_VALIDATION,
			self::customData([self::CUSTOM_DATA_FIELD => $field], $rowIndex),
		);
	}

	public static function readOnlyField(string $field, int|string|null $rowIndex = null): Error
	{
		return new Error(
			self::getMessage('READ_ONLY_FIELD', ['#FIELD#' => $field]),
			self::REQUEST_VALIDATION,
			self::customData([self::CUSTOM_DATA_FIELD => $field], $rowIndex),
		);
	}

	public static function emptyFields(): Error
	{
		return new Error(self::getEmptyFieldsMessage(), self::REQUEST_VALIDATION);
	}

	/**
	 * What this group calls a write it was given nothing to perform. Public because the transport refuses
	 * the same input before a scenario is ever reached - a request object knows the field set is empty
	 * without asking anyone - and the client must not be told two different things about one situation.
	 *
	 * @see \Bitrix\Crm\V2\Infrastructure\Rest\Request\Item\ProductRow\UpdateProductRowRequest
	 */
	public static function getEmptyFieldsMessage(): string
	{
		return self::getMessage('EMPTY_FIELDS');
	}

	public static function rowWithoutWritableFields(int|string $rowIndex): Error
	{
		return new Error(
			self::getMessage('ROW_WITHOUT_FIELDS', ['#INDEX#' => (string)$rowIndex]),
			self::REQUEST_VALIDATION,
			self::customData([], $rowIndex),
		);
	}

	public static function productNotFound(int $productId, int|string|null $rowIndex = null): Error
	{
		return new Error(
			self::getMessage('PRODUCT_NOT_FOUND', ['#PRODUCT_ID#' => (string)$productId]),
			self::PRODUCT_NOT_FOUND,
			self::customData([self::CUSTOM_DATA_PRODUCT_ID => $productId], $rowIndex),
		);
	}

	/**
	 * Gives an error raised by the owner update pipeline a code of this layer, keeping its message
	 * untouched. An error that already carries a code is returned as it is.
	 *
	 * The catalog rights check of the pipeline reports a refusal without any code
	 * ({@see ProductRowChecker}), and its message is the only handle there is - hence the comparison
	 * against the very phrases it builds its errors from.
	 *
	 * @param Error[] $errors
	 * @return Error[]
	 */
	public static function fromPipelineErrors(array $errors): array
	{
		return array_map(static fn(Error $error): Error => self::fromPipelineError($error), $errors);
	}

	public static function fromPipelineError(Error $error): Error
	{
		$code = $error->getCode();
		if ($code !== 0 && $code !== '' && $code !== null)
		{
			return $error;
		}

		$message = (string)$error->getMessage();

		return new Error(
			$message === '' ? self::getSaveFailedMessage() : $message,
			self::isCatalogRightsMessage($message) ? self::CATALOG_ACCESS_DENIED : self::SAVE_FAILED,
			$error->getCustomData(),
		);
	}

	/**
	 * The removal answered success while the row is still stored: an invariant of this group broken
	 * below it. To a caller this is a refused removal and carries the code of one - the row it asked to
	 * remove is still there - while the anomaly itself goes to the log, where an investigation can
	 * reach it.
	 */
	public static function removalDidNotHappen(): Error
	{
		return new Error(self::getSaveFailedMessage(), self::SAVE_FAILED);
	}

	/**
	 * What a refused save is called by this group, whatever the pipeline said about it. The message of a
	 * codeless refusal is written by an arbitrary handler of the save event and is therefore no part of any
	 * contract: a transport that may not answer with it answers with this one.
	 */
	public static function getSaveFailedMessage(): string
	{
		return self::getMessage('SAVE_FAILED');
	}

	private static function isCatalogRightsMessage(string $message): bool
	{
		if ($message === '')
		{
			return false;
		}

		Loc::loadMessages((string)(new \ReflectionClass(ProductRowChecker::class))->getFileName());

		foreach (['PRODUCT_ROW_CHECKER_PRICE_ERROR', 'PRODUCT_ROW_CHECKER_DISCOUNT_ERROR'] as $phraseCode)
		{
			if ($message === Loc::getMessage($phraseCode))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * @param array<string, mixed> $data
	 * @return array<string, mixed>|null
	 */
	private static function customData(array $data, int|string|null $rowIndex): ?array
	{
		if ($rowIndex !== null)
		{
			$data[self::CUSTOM_DATA_ROW_INDEX] = $rowIndex;
		}

		return $data === [] ? null : $data;
	}

	/**
	 * @param array<string, string> $replace
	 */
	private static function getMessage(string $suffix, array $replace = []): string
	{
		return (string)Loc::getMessage('CRM_V2_PRODUCT_ROW_ERROR_' . $suffix, $replace);
	}
}
