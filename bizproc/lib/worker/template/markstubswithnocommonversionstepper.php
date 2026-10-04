<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Worker\Template;

use Bitrix\Bizproc\Internal\Service\AiAgentGrid\Version\TemplateRevisionService;
use Bitrix\Bizproc\Internal\Service\Pilot\CommonRevisionWriter;
use Bitrix\Bizproc\Internal\Service\Pilot\PilotTemplateSettings;
use Bitrix\Bizproc\Internal\Service\Pilot\RestrictedTemplateArea;
use Bitrix\Bizproc\Public\Entity\Document\Workflow;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Main;

/**
 * Records "this template has never been published for everyone" for the stubs the editor created before
 * that answer was written down.
 *
 * The live row of a template of the new editor is created when the canvas is opened, and the stub it
 * carries is a scheme like any other: its fingerprint made "the template has a common version" true from
 * the first second of the template's life. Creation records the empty revision explicitly now, but only
 * for the templates created after that; the ones created earlier stay indistinguishable from published
 * ones until this stepper walks over them.
 *
 * Whether a template was ever published is not stored anywhere, so the stub is recognized by its shape:
 * nothing to execute, nothing filled in, no auto start. The fingerprint of the stub cannot serve as the
 * mark - a stub with a start trigger gets a random node name and therefore a fingerprint of its own every
 * time.
 *
 * Of the two ways to be wrong, calling a published template unpublished is the recoverable one: it is
 * undone by a single publication for everyone, while the opposite leaves the main scenario of the pilot
 * broken and the template visible to everyone under a pilot with no way to notice it.
 */
final class MarkStubsWithNoCommonVersionStepper extends Main\Update\Stepper
{
	protected static $moduleId = 'bizproc';

	private const STEP_ROWS_LIMIT = 100;

	public function __construct(
		private readonly PilotTemplateSettings $settings = new PilotTemplateSettings(),
		private readonly TemplateRevisionService $revisionService = new TemplateRevisionService(),
		private readonly CommonRevisionWriter $revisionWriter = new CommonRevisionWriter(),
		private readonly RestrictedTemplateArea $restrictedArea = new RestrictedTemplateArea(),
		private readonly int $pageSize = self::STEP_ROWS_LIMIT,
	)
	{
	}

	public function execute(array &$option): bool
	{
		$params = $this->getOuterParams();
		$lastId = (int)($params[0] ?? 0);
		$markedTotal = (int)($params[1] ?? 0);

		$rows = $this->readPage($lastId);
		if (!$rows)
		{
			return $this->finish($markedTotal);
		}

		$storedSettings = $this->settings->getByTemplateIds(array_column($rows, 'ID'));

		foreach ($rows as $row)
		{
			$templateId = (int)$row['ID'];
			$lastId = max($lastId, $templateId);

			$storedRevision = $storedSettings[$templateId][PilotTemplateSettings::COMMON_SCHEME_REVISION] ?? null;
			if ($this->isStubWithoutCommonVersion($row, $storedRevision))
			{
				$this->revisionWriter->markNoCommonVersion($templateId);
				++$markedTotal;
			}
		}

		if (count($rows) < $this->pageSize)
		{
			return $this->finish($markedTotal);
		}

		$this->setOuterParams([$lastId, $markedTotal]);

		return self::CONTINUE_EXECUTION;
	}

	/**
	 * Every template marked here enters the area of the pilot visibility rule, and the writer drops the
	 * stored area at every mark, so the walk leaves it correct on its own. It is dropped once more at the
	 * end all the same: the migration is the only moment a whole batch of templates enters that area at
	 * once, and the rule must not be answered from a state taken before it. Nothing at all is done on a
	 * portal where nothing was marked.
	 */
	private function finish(int $markedTotal): bool
	{
		if ($markedTotal > 0)
		{
			$this->restrictedArea->synchronize();
			$this->restrictedArea->invalidate();
		}

		return self::FINISH_EXECUTION;
	}

	private function readPage(int $lastId): array
	{
		[$moduleId, $entity, $documentType] = Workflow::getComplexType();

		return WorkflowTemplateTable::query()
			->setSelect(['ID', 'AUTO_EXECUTE', 'TEMPLATE', 'PARAMETERS', 'VARIABLES', 'CONSTANTS'])
			->where('MODULE_ID', $moduleId)
			->where('ENTITY', $entity)
			->where('DOCUMENT_TYPE', $documentType)
			->where('ID', '>', $lastId)
			->setOrder(['ID' => 'ASC'])
			->setLimit($this->pageSize)
			->fetchAll()
		;
	}

	/**
	 * @param string|null $storedRevision null while nothing was ever stored for the template.
	 */
	private function isStubWithoutCommonVersion(array $row, ?string $storedRevision): bool
	{
		if ($storedRevision === '')
		{
			// the answer is already recorded - by the creation of the template or by an earlier run
			return false;
		}

		if ((int)$row['AUTO_EXECUTE'] !== \CBPDocumentEventType::None)
		{
			return false;
		}

		if (!empty($row['PARAMETERS']) || !empty($row['VARIABLES']) || !empty($row['CONSTANTS']))
		{
			return false;
		}

		$scheme = is_array($row['TEMPLATE'] ?? null) ? $row['TEMPLATE'] : [];
		if ($this->hasExecutableActivity($scheme))
		{
			return false;
		}

		// a revision equal to the fingerprint of the live scheme is the lazy fill of the reader, not a
		// publication: the reader answers without the feature flag, so it can materialize the wrong value
		// between the update arriving and this stepper running
		return $storedRevision === null || $storedRevision === $this->liveRevision($scheme);
	}

	private function hasExecutableActivity(array $scheme): bool
	{
		$children = $scheme[0]['Children'] ?? [];
		if (!is_array($children))
		{
			return true;
		}

		foreach ($children as $child)
		{
			if (!$this->isTrigger($child))
			{
				return true;
			}
		}

		return false;
	}

	/**
	 * A trigger waits for an event and executes nothing by itself, so a scheme of triggers alone is still
	 * a stub. The type is matched case-insensitively: schemes written by the older surfaces keep it in
	 * lower case.
	 */
	private function isTrigger(mixed $child): bool
	{
		$type = is_array($child) ? (string)($child['Type'] ?? '') : '';

		return $type !== '' && str_ends_with(strtolower($type), 'trigger');
	}

	/**
	 * The fingerprint the lazy fill would store for this scheme, and null when the scheme cannot be
	 * fingerprinted at all - an answer that equals no stored value, so such a template is left alone.
	 */
	private function liveRevision(array $scheme): ?string
	{
		if (!$scheme)
		{
			return '';
		}

		try
		{
			return $this->revisionService->calculateRevision($scheme);
		}
		catch (\Throwable)
		{
			return null;
		}
	}
}
