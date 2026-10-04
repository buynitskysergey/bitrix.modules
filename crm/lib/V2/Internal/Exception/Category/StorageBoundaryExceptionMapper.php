<?php

declare(strict_types=1);

namespace Bitrix\Crm\V2\Internal\Exception\Category;

use Bitrix\Crm\Entry\EntryException;
use Bitrix\Crm\Model\ItemCategoryTable;
use Bitrix\Crm\Service\Container;
use Bitrix\Crm\Service\Localization;
use Bitrix\Crm\StatusTable;
use Bitrix\Crm\V2\Public\Entity\Category\CategoryError;
use Bitrix\Main\Error;
use Bitrix\Main\InvalidOperationException;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Result;
use Psr\Log\LoggerInterface;

/**
 * Turns whatever the storage boundary of the category domain reports into a {@see CategoryError}.
 *
 * The boundary refuses in two shapes, and both have to be covered: legacy `DealCategory::add/update/delete`
 * throw {@see EntryException}, a virtual category throws {@see InvalidOperationException}, while the
 * category entities and the ORM events of `ItemCategoryTable` / `StatusTable` answer with errors inside
 * a {@see Result}. `Category\Entity\DealCategory` even catches its own `EntryException` and forwards only
 * the text, so the same refusal arrives as an exception or as a result error depending on the caller.
 *
 * Recognition of a result error goes by phrase key, never by a hardcoded substring: the boundary carries
 * no error code of its own ({@see \Bitrix\Main\ORM\EntityError} stamps `BX_ERROR` on everything), so the
 * text is all there is, and the text is localized and rewritten by translators. The comparison pattern is
 * therefore built from the current text of the key, with `#PLACEHOLDER#` matching any value.
 *
 * The same phrases answer a second question a caller undoing a write has to ask - whether the boundary
 * refused before it wrote anything ({@see self::refusedBeforeWriting()}). It is asked here because the
 * phrases are the only thing that tells one refusal of the boundary from another.
 *
 * An unclassified refusal is a gap in this mapper, so it is always logged - and only logged. Whatever its
 * source, the text the boundary wrote never reaches the caller: a phrase we did not recognize is a phrase
 * we cannot vouch for, and it may name a table, a class or the shape of the legacy implementation. The
 * caller gets {@see CategoryError::CATEGORY_OPERATION_FAILED} and the log gets the original.
 *
 * @internal
 */
final class StorageBoundaryExceptionMapper
{
	private const LOGGER_ID = 'Default';

	/** @var array<string, string>|null */
	private ?array $boundaryPhrases = null;

	public function __construct(private readonly ?LoggerInterface $logger = null)
	{
	}

	public function mapThrowable(\Throwable $throwable): Error
	{
		$domainError = $this->recognizeThrowable($throwable);
		if ($domainError !== null)
		{
			return $domainError->toError();
		}

		$this->logUnclassifiedRefusal($throwable->getMessage(), $throwable);

		return CategoryError::CATEGORY_OPERATION_FAILED->toError();
	}

	/**
	 * A successful result passes through untouched, together with its data. A failed one is rebuilt from
	 * domain errors only, each of them once: the stage cascade of `ItemCategoryTable::onBeforeDelete()`
	 * repeats the same refusal per stage.
	 */
	public function mapResult(Result $result): Result
	{
		if ($result->isSuccess())
		{
			return $result;
		}

		$mapped = new Result();
		$added = [];
		foreach ($result->getErrors() as $boundaryError)
		{
			$domainError = $this->mapBoundaryError($boundaryError);

			$signature = $domainError->getCode() . "\0" . $domainError->getMessage();
			if (!isset($added[$signature]))
			{
				$added[$signature] = true;
				$mapped->addError($domainError);
			}
		}

		return $mapped;
	}

	/**
	 * Whether the boundary refused $result before it wrote anything, so that undoing the write has
	 * nothing to undo and the caches over storage are still telling the truth.
	 *
	 * WHY THE CATEGORY SIDE ASKS A MAPPER AND THE STAGE SIDE DOES NOT. A stage repository states the
	 * answer in the refusal itself
	 * ({@see \Bitrix\Crm\V2\Internal\Repository\Category\StageRepositoryInterface::DATA_KEY_WRITE_REACHED_STORAGE}),
	 * because every refusal it hands up is one call of `\CCrmStatus` or {@see StatusTable} it made and
	 * can place. A category repository cannot: what it hands up is the refusal of
	 * {@see \Bitrix\Crm\Category\Entity\Category::save()} or `delete()`, one legacy call that runs its
	 * own checks and a cascade over the stages of the category, and the result says nothing about where
	 * inside it the refusal came from. The phrases are the only thing that tells one from another, which
	 * is why the question is asked here and not in the repository.
	 *
	 * The two answers do not overlap and neither can be swapped for the other. What this method can say
	 * `true` to is a deletion refused by a check that runs before any write, and that is precisely the
	 * case a repository has no way of recognizing; a write of two steps refused on its second one -
	 * where the repository does know - never matches a phrase here and so is answered `false`, which is
	 * the answer it needs.
	 *
	 * Named here are the refusals of the checks a deletion runs first, and only those:
	 * {@see ItemCategoryTable::onBeforeDelete()} answers all three of its own before the stage cascade
	 * begins, and {@see \Bitrix\Crm\Category\DealCategory::delete()} answers the dependent deals of a
	 * Deal category before it touches the table. Every other refusal is answered `false`, the stage
	 * cascade above all - it erases stage after stage and can refuse on the last of them, which is the
	 * very case a caller drops its caches for. `NOT_FOUND` is left out on purpose: an update answers
	 * it too, and by then a write of the same operation may already have gone through.
	 *
	 * A phrase this mapper does not know is `false` as well: the safe answer to a refusal we cannot
	 * place is that storage was reached. A refusal carrying no text at all is that same answer and not
	 * an empty list of phrases to agree with: {@see Loc::getMessage()} of a key the portal no longer
	 * has is `null`, {@see Error::__construct()} takes it, and
	 * {@see ItemCategoryTable::onBeforeDelete()} copies the messages of the stage cascade over as they
	 * come - so a cascade that already erased stages could otherwise pass for a refusal before the
	 * first write.
	 */
	public function refusedBeforeWriting(Result $result): bool
	{
		if ($result->isSuccess())
		{
			return false;
		}

		foreach ($result->getErrors() as $boundaryError)
		{
			$lines = self::linesOf((string)$boundaryError->getMessage());
			if ($lines === [])
			{
				return false;
			}

			foreach ($lines as $line)
			{
				if (!$this->matchesAnyPhrase($line, self::phraseKeysRefusedBeforeWriting()))
				{
					return false;
				}
			}
		}

		return true;
	}

	private function mapBoundaryError(Error $boundaryError): Error
	{
		$message = trim((string)$boundaryError->getMessage());

		$domainError = $this->recognizePhrase($message);
		if ($domainError !== null)
		{
			return $domainError->toError();
		}

		$this->logUnclassifiedRefusal($message);

		return CategoryError::CATEGORY_OPERATION_FAILED->toError();
	}

	private function recognizeThrowable(\Throwable $throwable): ?CategoryError
	{
		if ($throwable instanceof EntryException)
		{
			$byCode = match ((int)$throwable->getCode())
			{
				EntryException::NOT_FOUND => CategoryError::CATEGORY_NOT_FOUND,
				EntryException::DEPENDENCIES_FOUND => CategoryError::DEPENDENT_ITEMS_EXIST,
				default => null,
			};

			if ($byCode !== null)
			{
				return $byCode;
			}
		}
		elseif ($throwable instanceof InvalidOperationException)
		{
			return CategoryError::OPERATION_NOT_ALLOWED_FOR_CATEGORY;
		}

		// A general EntryException carries the boundary texts it was built from, joined by a line break.
		return $this->recognizePhrase($throwable->getMessage());
	}

	private function recognizePhrase(string $message): ?CategoryError
	{
		foreach (self::linesOf($message) as $line)
		{
			foreach (self::domainErrorByPhraseKey() as $phraseKey => $domainError)
			{
				$phrase = $this->boundaryPhrases()[$phraseKey] ?? '';
				if ($phrase !== '' && $this->phraseMatches($phrase, $line))
				{
					return $domainError;
				}
			}
		}

		return null;
	}

	/**
	 * @param string[] $phraseKeys
	 */
	private function matchesAnyPhrase(string $message, array $phraseKeys): bool
	{
		foreach ($phraseKeys as $phraseKey)
		{
			$phrase = $this->boundaryPhrases()[$phraseKey] ?? '';
			if ($phrase !== '' && $this->phraseMatches($phrase, $message))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * The lines a boundary refusal is made of: a general {@see EntryException} carries the texts it was
	 * built from, joined by a line break.
	 *
	 * @return string[]
	 */
	private static function linesOf(string $message): array
	{
		$lines = [];
		foreach (preg_split('/\R/u', $message) ?: [] as $line)
		{
			$line = trim($line);
			if ($line !== '')
			{
				$lines[] = $line;
			}
		}

		return $lines;
	}

	/**
	 * Whether $message is what $phrase reads like once the boundary has filled its placeholders in.
	 *
	 * A phrase whose text is nothing but placeholders is never a match, however well the pattern built
	 * from it would fit: with no literal left to recognize it by, that pattern is `.*` and would hand
	 * the code of this phrase to every refusal the domain cannot classify. The phrases are written by
	 * translators, so this is a shape the class has to survive rather than a shape it can rule out.
	 */
	private function phraseMatches(string $phrase, string $message): bool
	{
		$literals = preg_split('/#[A-Z\d_]+#/u', $phrase) ?: [$phrase];
		if (trim(implode('', $literals)) === '')
		{
			return false;
		}

		$pattern = implode('.*', array_map(
			static fn(string $literal): string => preg_quote($literal, '/'),
			$literals,
		));

		return (bool)preg_match('/^' . $pattern . '$/su', $message);
	}

	/**
	 * @return array<string, string>
	 */
	private function boundaryPhrases(): array
	{
		if ($this->boundaryPhrases === null)
		{
			$this->boundaryPhrases = [];
			foreach (self::phraseSourceClasses() as $class)
			{
				$file = (new \ReflectionClass($class))->getFileName();
				if ($file !== false)
				{
					$this->boundaryPhrases += Loc::loadLanguageFile($file);
				}
			}
		}

		return $this->boundaryPhrases;
	}

	/**
	 * The refusals of the storage boundary this domain classifies. Order is the matching order.
	 *
	 * @return array<string, CategoryError>
	 */
	private static function domainErrorByPhraseKey(): array
	{
		return [
			'CRM_ENTRY_EX_DEAL_CATEGORY_NOT_FOUND' => CategoryError::CATEGORY_NOT_FOUND,

			'CRM_ENTRY_EX_DEAL_CATEGORY_DEPENDENCIES_FOUND' => CategoryError::DEPENDENT_ITEMS_EXIST,
			'CRM_CATEGORY_TABLE_DELETE_ERROR_ITEMS' => CategoryError::DEPENDENT_ITEMS_EXIST,
			'CRM_STATUS_STAGE_WITH_ITEMS_ERROR' => CategoryError::DEPENDENT_ITEMS_EXIST,

			'CRM_TYPE_CATEGORY_ADD_ERROR_SYSTEM' => CategoryError::OPERATION_NOT_ALLOWED_FOR_CATEGORY,
			'CRM_TYPE_CATEGORY_DELETE_ERROR_SYSTEM' => CategoryError::OPERATION_NOT_ALLOWED_FOR_CATEGORY,
			'CRM_CATEGORY_TABLE_DELETE_ERROR_DEFAULT' => CategoryError::OPERATION_NOT_ALLOWED_FOR_CATEGORY,

			'CRM_STATUS_MORE_THAN_ONE_SUCCESS_ERROR' => CategoryError::OPERATION_NOT_ALLOWED_FOR_STAGE,
			'CRM_STATUS_INCORRECT_SEMANTIC_SORT_ERROR' => CategoryError::OPERATION_NOT_ALLOWED_FOR_STAGE,
			'CRM_STATUS_INCORRECT_PROCESS_SEMANTIC_SORT_ERROR' => CategoryError::OPERATION_NOT_ALLOWED_FOR_STAGE,
			'CRM_STATUS_UNSUPPORTED_SEMANTIC_ERROR' => CategoryError::OPERATION_NOT_ALLOWED_FOR_STAGE,
			'CRM_STATUS_FIELD_UPDATE_ERROR' => CategoryError::OPERATION_NOT_ALLOWED_FOR_STAGE,
			'CRM_STATUS_SUCCESS_SEMANTIC_UPDATE_ERROR' => CategoryError::OPERATION_NOT_ALLOWED_FOR_STAGE,

			'CRM_FEATURE_RESTRICTION_ERROR' => CategoryError::TARIFF_LIMIT_EXCEEDED,
		];
	}

	/**
	 * The refusals of {@see self::refusedBeforeWriting()}, all of them raised by a check a deletion
	 * runs before the first write of the same operation.
	 *
	 * @return string[]
	 */
	private static function phraseKeysRefusedBeforeWriting(): array
	{
		return [
			'CRM_ENTRY_EX_DEAL_CATEGORY_DEPENDENCIES_FOUND',
			'CRM_CATEGORY_TABLE_DELETE_ERROR_ITEMS',
			'CRM_CATEGORY_TABLE_DELETE_ERROR_DEFAULT',
			'CRM_TYPE_CATEGORY_DELETE_ERROR_SYSTEM',
		];
	}

	/**
	 * Classes whose language twin holds the phrases above.
	 *
	 * @return array<class-string>
	 */
	private static function phraseSourceClasses(): array
	{
		return [
			EntryException::class,
			ItemCategoryTable::class,
			StatusTable::class,
			Localization::class,
		];
	}

	private function logUnclassifiedRefusal(string $message, ?\Throwable $throwable = null): void
	{
		$context = ['message' => $message];
		if ($throwable !== null)
		{
			$context['exception'] = $throwable;
		}

		$this->resolveLogger()->error(
			'CRM category domain got a storage boundary refusal it cannot classify: {message}',
			$context,
		);
	}

	private function resolveLogger(): LoggerInterface
	{
		return $this->logger ?? Container::getInstance()->getLogger(self::LOGGER_ID);
	}
}
