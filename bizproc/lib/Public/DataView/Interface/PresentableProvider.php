<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\DataView\Interface;

use Bitrix\Bizproc\Public\DataView\Dto\SourceRef;

/**
 * Optional capability of a source to humanize its own dictionary/reference field values for display —
 * an extension of the {@see DataSourceProvider} SPI, not a replacement: a provider without this
 * interface contributes no labels and the raw extracted value is shown as-is.
 */
interface PresentableProvider
{
	/**
	 * Returns, per input row key (keys preserved), a map of source-field code to human-readable
	 * label for dictionary/reference fields only. Untouched fields are omitted; a row with nothing
	 * to format maps to an empty array. The join/id value is never rewritten here.
	 *
	 * A label is plain text, never HTML: escaping belongs to whoever puts it into a page, so a label
	 * escaped here would reach the user double-escaped.
	 *
	 * The signature stays at two parameters so an implementation shipped by a module on its own release
	 * cycle keeps loading against a newer bizproc. The actor a row was extracted under is passed as an
	 * extra trailing int argument: a provider that builds labels under permissions declares it as
	 * `int $actorId = 0` and gets it, one that does not simply ignores it.
	 *
	 * @param iterable<int|string, array<string, mixed>> $rows keyed by source-field code
	 * @return iterable<int|string, array<string, string>>
	 */
	public function present(SourceRef $source, iterable $rows): iterable;
}
