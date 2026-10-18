<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\Import\Source;

/**
 * Renders a single landing page (array of block rows) into note-compatible markdown.
 *
 * Attachments must be emitted using {@see LandingSource::ATTACHMENT_SCHEME} so the
 * download step can resolve them via the source-agnostic attachment pipeline.
 */
interface LandingBlockMarkdownRenderer
{
	/**
	 * @param array<int, array{ID:int, CODE:string, CONTENT:string, ANCHOR:?string, SORT:int, DESIGNED:string, SOURCE_PARAMS:?array}> $blocks
	 */
	public function renderPage(array $blocks): string;
}
