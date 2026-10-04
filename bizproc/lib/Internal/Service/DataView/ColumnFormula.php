<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\DataView;

use Bitrix\Bizproc\Calc\Arguments;
use Bitrix\Bizproc\Calc\Functions;
use Bitrix\Bizproc\Calc\Parser;
use Bitrix\Bizproc\Internal\Exception\DataView\InvalidDataViewDefinitionException;

/**
 * An optional bizproc expression bound to a data view column: it replaces the value the column took
 * from its source. Validation ({@see ColumnResolver}), evaluation ({@see CombineEngine}) and output
 * modifiers ({@see ValuePresenter}) read the formula through this one place, so an expression accepted
 * on save is the very expression computed for the preview and for the materialized row.
 *
 * References mean columns of the same row: {=Column:CODE}, optionally with an output modifier
 * ({=Column:CODE > printable}).
 */
final class ColumnFormula
{
	public const REFERENCE_OBJECT = 'column';

	/** Functions whose result is not reproducible between the preview and the materialized row. */
	private const NON_DETERMINISTIC_FUNCTIONS = ['rand', 'randstring', 'shuffle'];

	/** Functions that read a running workflow through the activity a data view does not have. */
	private const WORKFLOW_BOUND_FUNCTIONS = [
		'getdocumenturl',
		'touserdate',
		'getuserdateoffset',
		'workdateadd',
		'isworkday',
		'isworktime',
	];

	/** Parser error codes meaning the expression itself is broken, not the values it was fed. */
	private const SYNTAX_ERROR_CODES = [0, 1, 2, 3, 4, 5, 7];

	/** Parser error code for a name the function catalog has no. */
	private const UNKNOWN_FUNCTION_ERROR_CODE = 3;

	private const PROBE_VALUE = 1;

	private const MAX_NUMBER_FORMAT_DECIMALS = 100;

	/**
	 * Formulas of the definition keyed by the column code they are bound to. A stamp column holds a
	 * constant instead of a source value and carries no formula.
	 *
	 * @return array<string, string>
	 */
	public static function mapByColumnCode(array $definition): array
	{
		$formulas = [];
		foreach ((array)($definition['columns'] ?? []) as $column)
		{
			$column = (array)$column;
			$code = (string)($column['code'] ?? '');
			$formula = self::read($column);
			if ($code === '' || $formula === null || ($column['kind'] ?? null) === 'constant')
			{
				continue;
			}

			$formulas[$code] = $formula;
		}

		return $formulas;
	}

	public static function read(array $column): ?string
	{
		$formula = $column['formula'] ?? null;
		if (!is_string($formula))
		{
			return null;
		}

		$formula = trim($formula);

		return $formula === '' ? null : $formula;
	}

	/**
	 * The referenced column and its output modifier, when the whole formula is a single reference.
	 * Such a formula needs no computation: the value is taken as is and the modifier is an instruction
	 * for the presentation layer.
	 *
	 * @return array{code: string, format: string}|null
	 */
	public static function pureReference(string $formula): ?array
	{
		if (!preg_match(\CBPActivity::ValuePattern, trim($formula), $matches))
		{
			return null;
		}

		if (mb_strtolower($matches['object']) !== self::REFERENCE_OBJECT)
		{
			return null;
		}

		return [
			'code' => $matches['field'],
			'format' => mb_strtolower((string)($matches['mod1'] ?? '')),
		];
	}

	/**
	 * @param array<string, true> $knownCodes result column codes of the same definition
	 * @param array<string, true> $formulaCodes codes of the columns that carry a formula themselves
	 * @throws InvalidDataViewDefinitionException
	 */
	public static function assertValid(string $formula, string $columnCode, array $knownCodes, array $formulaCodes): void
	{
		$reference = self::pureReference($formula);
		if ($reference !== null)
		{
			self::assertReferenceResolvable($reference['code'], $columnCode, $knownCodes, $formulaCodes);

			return;
		}

		foreach (self::inlineReferences($formula) as $inlineReference)
		{
			if (mb_strtolower($inlineReference['object']) !== self::REFERENCE_OBJECT)
			{
				throw InvalidDataViewDefinitionException::forField(
					$columnCode,
					sprintf(
						'DataView column formula may reference table columns only, got "%s".',
						$inlineReference['object'],
					),
					InvalidDataViewDefinitionException::VIOLATION_FORMULA_REFERENCE,
				);
			}

			// an expression is computed over raw values, so a modifier inside it would be silently dropped
			if ($inlineReference['format'] !== '')
			{
				throw InvalidDataViewDefinitionException::forField(
					$columnCode,
					sprintf(
						'DataView column formula may use output modifier "%s" only as the whole formula.',
						$inlineReference['format'],
					),
					InvalidDataViewDefinitionException::VIOLATION_FORMULA_REFERENCE,
				);
			}

			self::assertReferenceResolvable($inlineReference['code'], $columnCode, $knownCodes, $formulaCodes);
		}

		self::assertComputable($formula, $columnCode);
	}

	/**
	 * @param array<string, mixed> $rowValues row values keyed by column code, taken before any formula
	 *   was applied, so the result does not depend on the order the columns are computed in
	 */
	public static function evaluate(string $formula, array $rowValues): mixed
	{
		return self::evaluator($formula)($rowValues);
	}

	/**
	 * Reader of one formula over any row of the table. The expression is parsed here and not again for
	 * every row, so a column of a thousand rows is tokenized once.
	 *
	 * @return \Closure(array<string, mixed>): mixed
	 */
	public static function evaluator(string $formula): \Closure
	{
		$reference = self::pureReference($formula);
		if ($reference !== null)
		{
			$code = $reference['code'];

			return static fn (array $rowValues): mixed => $rowValues[$code] ?? null;
		}

		$row = [];
		$parser = new Parser(
			self::activity(static function (string $code) use (&$row): mixed {
				return $row[$code] ?? null;
			}),
			self::functions(),
		);

		$notation = $parser->parse($formula);
		if ($notation === false)
		{
			return static fn (array $rowValues): string => '';
		}

		// The rows share one parser, and errors are dropped before each of them: an error of one row does
		// not empty the rows after it.
		return static function (array $rowValues) use ($parser, $notation, &$row): mixed {
			$row = $rowValues;
			$parser->clearErrors();
			$result = $parser->calculateNotation($notation);

			return $parser->getErrors() === [] ? \CBPHelper::stringify($result) : '';
		};
	}

	/**
	 * Evaluators of the definition keyed by the column code they compute.
	 *
	 * @return array<string, \Closure(array<string, mixed>): mixed>
	 */
	public static function evaluatorsByColumnCode(array $definition): array
	{
		return array_map(self::evaluator(...), self::mapByColumnCode($definition));
	}

	/**
	 * Computed cells of one row: every formula reads the row as the sources composed it, so the result
	 * does not depend on the order the columns are computed in and a formula cannot feed another one.
	 * Preview and materialization compute a shown row through this one place, so they cannot drift.
	 *
	 * @param array<string, \Closure(array<string, mixed>): mixed> $evaluators
	 * @param array<string, mixed> $values
	 * @return array<string, mixed>
	 */
	public static function applyToRow(array $evaluators, array $values): array
	{
		$computed = $values;
		foreach ($evaluators as $code => $evaluator)
		{
			if (array_key_exists($code, $values))
			{
				$computed[$code] = $evaluator($values);
			}
		}

		return $computed;
	}

	/**
	 * The function catalog a data view formula is computed with: numberformat is capped there, because a
	 * column asking for millions of decimals would spend a byte of memory per digit of every row.
	 */
	private static function functions(): array
	{
		$functions = Functions::getList();
		$numberFormat = $functions['numberformat']['func'];

		$functions['numberformat']['func'] = static function (Arguments $args) use ($numberFormat): mixed {
			$values = $args->getArray();
			if (isset($values[1]) && is_scalar($values[1]))
			{
				$values[1] = min(self::MAX_NUMBER_FORMAT_DECIMALS, (int)$values[1]);
				$args->setArgs($values);
			}

			return $numberFormat($args);
		};

		return $functions;
	}

	/** @return array<string, true> */
	public static function allowedFunctions(): array
	{
		$forbidden = array_merge(self::NON_DETERMINISTIC_FUNCTIONS, self::WORKFLOW_BOUND_FUNCTIONS);

		return array_fill_keys(array_diff(array_keys(Functions::getList()), $forbidden), true);
	}

	/**
	 * @param array<string, true> $knownCodes
	 * @param array<string, true> $formulaCodes
	 * @throws InvalidDataViewDefinitionException
	 */
	private static function assertReferenceResolvable(
		string $code,
		string $columnCode,
		array $knownCodes,
		array $formulaCodes,
	): void
	{
		if (!isset($knownCodes[$code]))
		{
			throw InvalidDataViewDefinitionException::forField(
				$columnCode,
				sprintf('DataView column formula references column "%s", which the table has no.', $code),
				InvalidDataViewDefinitionException::VIOLATION_FORMULA_REFERENCE,
			);
		}

		if ($code !== $columnCode && isset($formulaCodes[$code]))
		{
			throw InvalidDataViewDefinitionException::forField(
				$columnCode,
				sprintf('DataView column formula cannot reference computed column "%s".', $code),
				InvalidDataViewDefinitionException::VIOLATION_FORMULA_REFERENCE,
			);
		}
	}

	/**
	 * @param string[] $names lower-cased names of the functions the expression calls
	 * @throws InvalidDataViewDefinitionException
	 */
	private static function assertFunctionsAllowed(array $names, string $columnCode): void
	{
		$allowed = self::allowedFunctions();
		foreach ($names as $name)
		{
			if (!isset($allowed[$name]))
			{
				throw InvalidDataViewDefinitionException::forField(
					$columnCode,
					sprintf('DataView column formula uses function "%s", which is not available here.', $name),
					InvalidDataViewDefinitionException::VIOLATION_FORMULA_FUNCTION,
				);
			}
		}
	}

	/**
	 * The allowed functions and the syntax are both read from the one parse of {@see Parser}, the very
	 * program a row will be computed with: a lexer of its own here would sooner or later accept a call
	 * the parser then makes anyway.
	 *
	 * The expression is computed over a probe row, so the verdict is about the expression alone: a value
	 * dependent failure (division by zero, arguments a real row may not produce) is not a reason to
	 * reject the definition.
	 *
	 * @throws InvalidDataViewDefinitionException
	 */
	private static function assertComputable(string $formula, string $columnCode): void
	{
		$parser = new Parser(self::activity(static fn (): int => self::PROBE_VALUE), self::functions());

		$notation = $parser->parse($formula);
		if ($notation === false)
		{
			throw self::parseFailure($parser->getErrors(), $columnCode);
		}

		self::assertFunctionsAllowed($parser->getCalledFunctions($notation), $columnCode);

		$parser->calculateNotation($notation);

		foreach ($parser->getErrors() as [$errorCode, $message])
		{
			if (in_array($errorCode, self::SYNTAX_ERROR_CODES, true))
			{
				throw InvalidDataViewDefinitionException::forField(
					$columnCode,
					sprintf('DataView column formula is not a valid expression: %s', $message),
					InvalidDataViewDefinitionException::VIOLATION_FORMULA_INVALID,
				);
			}
		}
	}

	/**
	 * A parse stops at its first error, so that error is the whole verdict. An unknown name is reported
	 * as a function violation: for the author it is the same mistake as a function that is not allowed.
	 *
	 * @param array<int, array{0: int, 1: string}> $errors
	 */
	private static function parseFailure(array $errors, string $columnCode): InvalidDataViewDefinitionException
	{
		[$errorCode, $message] = $errors[0] ?? [null, ''];
		$isUnknownFunction = $errorCode === self::UNKNOWN_FUNCTION_ERROR_CODE;

		return InvalidDataViewDefinitionException::forField(
			$columnCode,
			$isUnknownFunction
				? sprintf('DataView column formula uses a function that is not available here: %s', $message)
				: sprintf('DataView column formula is not a valid expression: %s', $message),
			$isUnknownFunction
				? InvalidDataViewDefinitionException::VIOLATION_FORMULA_FUNCTION
				: InvalidDataViewDefinitionException::VIOLATION_FORMULA_INVALID,
		);
	}

	/**
	 * @return array<int, array{object: string, code: string, format: string}>
	 */
	private static function inlineReferences(string $formula): array
	{
		preg_match_all(\CBPActivity::ValueInlinePattern, $formula, $matches, PREG_SET_ORDER);

		$references = [];
		foreach ($matches as $match)
		{
			$references[] = [
				'object' => $match['object'],
				'code' => $match['field'],
				'format' => mb_strtolower((string)($match['mod1'] ?? '')),
			];
		}

		return $references;
	}

	/**
	 * {@see Parser} reaches for an activity for one thing only — to resolve a reference into a value,
	 * so a row of a data view is enough of an activity for it.
	 *
	 * @param \Closure(string): mixed $resolve value of the referenced column
	 */
	private static function activity(\Closure $resolve): \CBPActivity
	{
		return new class('DataViewColumnFormula', $resolve) extends \CBPActivity {
			public function __construct($name, private readonly \Closure $resolve)
			{
				parent::__construct($name);
			}

			// there is no workflow above this activity, so the inherited walk up to the root never ends
			public function getDocumentId()
			{
				return [];
			}

			public function parseValue($value, $convertToType = null, ?callable $decorator = null)
			{
				$reference = is_string($value) ? ColumnFormula::pureReference($value) : null;

				return $reference === null ? null : ($this->resolve)($reference['code']);
			}
		};
	}
}
