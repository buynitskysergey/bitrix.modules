<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Service\LastValues;

use Bitrix\Bizproc\Internal\Config\LastValues;
use Bitrix\Bizproc\Internal\Model\LastValues\WorkflowLastValuesTable;
use Bitrix\Bizproc\Workflow\Template\Entity\WorkflowTemplateTable;
use Bitrix\Main\Diag\LoggerFactory;
use Bitrix\Main\Type\DateTime;
use Bitrix\Main\Web\Json;
use Psr\Log\LoggerInterface;

/**
 * Cross-module reading point of the last finished run snapshot. The service owns what belongs to the
 * data itself - the actuality rule and the access to the source document; the right to edit the
 * template is checked by the calling controller.
 */
class LastValuesService
{
	private const LOGGER_ID = 'bizproc.last_values';

	private LastValues $config;
	private ?LoggerInterface $logger = null;

	public function __construct(?LastValues $config = null)
	{
		$this->config = $config ?? new LastValues();
	}

	/**
	 * Availability of the whole mechanism: the single option of this module gates the capture, the
	 * reading and the editor feature code alike.
	 */
	public function isAvailable(): bool
	{
		return $this->config->isCaptureEnabled();
	}

	/**
	 * Values of the last finished run of the template. An empty map is the regular answer - no run yet,
	 * an outdated snapshot, an inaccessible source document or a disabled feature; nothing is thrown
	 * outwards, so a reading failure cannot break the caller.
	 *
	 * @return array<string, array{
	 *     value: string|int|float|bool|array,
	 *     type: string,
	 *     multiple: bool,
	 *     truncated: bool,
	 *     totalCount: int|null,
	 * }> expression => captured value
	 */
	public function getValues(int $templateId, int $userId): array
	{
		if (!$this->isAvailable() || $templateId <= 0)
		{
			return [];
		}

		try
		{
			return $this->readValues($templateId, $userId);
		}
		catch (\Throwable $exception)
		{
			// identifiers only: captured values must never reach the log
			$this->getLogger()?->warning(
				'Bizproc last values reading failed for template {templateId}: {message}',
				[
					'templateId' => $templateId,
					'message' => $exception->getMessage(),
				],
			);

			return [];
		}
	}

	private function readValues(int $templateId, int $userId): array
	{
		$row = WorkflowLastValuesTable::getByPrimary($templateId)->fetch() ?: null;
		if ($row === null)
		{
			return [];
		}

		if (!$this->isActualVersion($templateId, $row['VERSION_KEY'] ?? null))
		{
			return [];
		}

		if (!$this->canReadSourceDocument($row, $userId))
		{
			return [];
		}

		$decoded = Json::decode((string)($row['VALUES_DATA'] ?? ''));

		return is_array($decoded) ? $decoded : [];
	}

	/**
	 * A snapshot belongs to the template version it was taken against: a republished template (and a
	 * deleted one, which has no version at all) discards the stored values.
	 */
	private function isActualVersion(int $templateId, mixed $versionKey): bool
	{
		if (!$versionKey instanceof DateTime)
		{
			return false;
		}

		$template = WorkflowTemplateTable::getByPrimary($templateId, ['select' => ['MODIFIED']])->fetch();
		$modified = is_array($template) ? ($template['MODIFIED'] ?? null) : null;

		return $modified instanceof DateTime && $modified->getTimestamp() === $versionKey->getTimestamp();
	}

	/**
	 * Deny by default: values of unknown origin are not handed out. A snapshot without a source document
	 * has nothing to authorize against, and the capture never writes one, so this is the same refusal the
	 * caller already gets for an inaccessible document - an empty map, not a distinguishable answer.
	 */
	private function canReadSourceDocument(array $row, int $userId): bool
	{
		$documentId = (string)($row['DOCUMENT_ID'] ?? '');
		if ($documentId === '')
		{
			return false;
		}

		return \CBPDocument::canUserOperateDocument(
			\CBPCanUserOperateOperation::ReadDocument,
			$userId,
			[
				(string)($row['MODULE_ID'] ?? ''),
				(string)($row['ENTITY'] ?? ''),
				$documentId,
			],
		);
	}

	/**
	 * Null while the logger is switched off in the registry: there is nothing to write the diagnostics to,
	 * so the reading skips it instead of feeding a NullLogger.
	 */
	private function getLogger(): ?LoggerInterface
	{
		return $this->logger ??= (new LoggerFactory(alwaysReturnLogger: false))->createById(self::LOGGER_ID);
	}
}
