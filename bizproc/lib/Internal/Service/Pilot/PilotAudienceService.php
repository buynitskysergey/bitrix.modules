<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Service\Pilot;

use Bitrix\Bizproc\Internal\Entity\WorkflowTemplate\AudienceMatch;
use Bitrix\Bizproc\Internal\Exception\Pilot\AudienceUnavailableException;
use Bitrix\Bizproc\Internal\Model\Pilot\WorkflowTemplatePilotAccessTable;
use Bitrix\Bizproc\Internal\Model\Pilot\WorkflowTemplatePilotTable;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Query\Join;

/**
 * The single answer to "does this employee belong to the audience of this pilot". The service answers
 * about membership and about nothing else: it is not a right, and being in an audience cancels none of
 * the ordinary permission checks.
 *
 * The nesting of a department is encoded by the code itself - D{id} is the department alone, DR{id} is
 * the department with the nested ones - so membership is an intersection of two sets of strings and
 * costs no walk over the tree. The same property makes the audience follow the org structure without
 * republishing anything: the composition of the codes is recalculated by the platform.
 *
 * The batch answer is the one the budget stands on: a check per template would turn a list of a
 * hundred templates into a hundred queries, so the whole selection is asked in a single one.
 *
 * The service stands on a path shared by the whole product, so it starts with the presence counter:
 * while the portal has no pilot at all, it costs neither a query nor a read of the access codes.
 */
class PilotAudienceService
{
	/**
	 * The families an audience is built of - separate employees and departments, of the old structure
	 * and of the new one. An employee carries codes of many other families, and thousands of
	 * them; letting those into the IN would cost the query nothing but size.
	 */
	private const AUDIENCE_CODE_PREFIXES = ['U', 'D', 'DR', 'SND', 'SNDR', 'SNT', 'SNTR'];

	private const AUDIENCE_RELATION = 'AUDIENCE';

	public function __construct(
		private readonly PilotPresence $presence = new PilotPresence(),
		private readonly UserAccessCodeProvider $userAccessCodes = new UserAccessCodeProvider(),
	)
	{
	}

	/**
	 * The single allowlist shared by the write and runtime paths. A code accepted for publication must be
	 * one the membership check can later match against an employee.
	 */
	public static function isSupportedAccessCode(string $code): bool
	{
		return preg_match('/^([A-Z]+)[1-9]\d*$/', $code, $matches) === 1
			&& in_array($matches[1], self::AUDIENCE_CODE_PREFIXES, true)
		;
	}

	/**
	 * @throws AudienceUnavailableException the membership could not be established
	 */
	public function isInAudience(int $userId, int $templateId): bool
	{
		return $this->classifyTemplate($userId, $templateId) === AudienceMatch::Matched;
	}

	/**
	 * Answers both whether the template has a pilot and whether the employee matches it in one query.
	 *
	 * @throws AudienceUnavailableException the membership could not be established
	 */
	public function classifyTemplate(int $userId, int $templateId): AudienceMatch
	{
		return $this->classifyTemplateWithPilotId($userId, $templateId)['match'];
	}

	/**
	 * @return array{match: AudienceMatch, pilotId: int|null}
	 * @throws AudienceUnavailableException the membership could not be established
	 */
	public function classifyTemplateWithPilotId(int $userId, int $templateId): array
	{
		if ($userId <= 0 || $templateId <= 0 || !$this->presence->hasAnyPilot())
		{
			return ['match' => AudienceMatch::NotApplicable, 'pilotId' => null];
		}

		$codes = $this->audienceCodesOf($userId);
		if (!$codes)
		{
			return $this->queryTemplateMatch($templateId, []);
		}

		return $this->queryTemplateMatch($templateId, $codes);
	}

	/**
	 * @param int[] $templateIds
	 * @return int[] the templates of the given list whose audience the employee belongs to
	 * @throws AudienceUnavailableException the membership could not be established
	 */
	public function matchTemplates(int $userId, array $templateIds): array
	{
		$templateIds = $this->normalizeTemplateIds($templateIds);
		if ($userId <= 0 || !$templateIds || !$this->presence->hasAnyPilot())
		{
			return [];
		}

		$codes = $this->audienceCodesOf($userId);
		if (!$codes)
		{
			return [];
		}

		return $this->queryMatchedTemplates($templateIds, $codes);
	}

	/**
	 * @param int[] $templateIds
	 * @param string[] $codes
	 * @return int[]
	 * @throws AudienceUnavailableException
	 */
	private function queryMatchedTemplates(array $templateIds, array $codes): array
	{
		try
		{
			$rows = WorkflowTemplatePilotTable::query()
				->registerRuntimeField(
					self::AUDIENCE_RELATION,
					new Reference(
						self::AUDIENCE_RELATION,
						WorkflowTemplatePilotAccessTable::class,
						Join::on('this.ID', 'ref.PILOT_ID'),
						['join_type' => Join::TYPE_INNER],
					),
				)
				->setSelect(['TEMPLATE_ID'])
				->whereIn('TEMPLATE_ID', $templateIds)
				->whereIn(self::AUDIENCE_RELATION. '.ACCESS_CODE', $codes)
				->setDistinct()
				->fetchAll()
			;
		}
		catch (\Throwable $exception)
		{
			throw AudienceUnavailableException::audienceStorageUnavailable($exception);
		}

		return array_map('intval', array_column($rows, 'TEMPLATE_ID'));
	}

	/**
	 * @param string[] $codes
	 * @return array{match: AudienceMatch, pilotId: int|null}
	 * @throws AudienceUnavailableException
	 */
	private function queryTemplateMatch(int $templateId, array $codes): array
	{
		try
		{
			$query = WorkflowTemplatePilotTable::query()
				->setSelect(['ID'])
				->where('TEMPLATE_ID', $templateId)
				->setLimit(1)
			;

			if ($codes)
			{
				$query
					->registerRuntimeField(
						self::AUDIENCE_RELATION,
						new Reference(
							self::AUDIENCE_RELATION,
							WorkflowTemplatePilotAccessTable::class,
							Join::on('this.ID', 'ref.PILOT_ID')->whereIn('ref.ACCESS_CODE', $codes),
							['join_type' => Join::TYPE_LEFT],
						),
					)
					->addSelect(self::AUDIENCE_RELATION. '.ACCESS_CODE', 'MATCHED_ACCESS_CODE')
				;
			}

			$row = $query->fetch();
		}
		catch (\Throwable $exception)
		{
			throw AudienceUnavailableException::audienceStorageUnavailable($exception);
		}

		if ($row === false)
		{
			return ['match' => AudienceMatch::NotApplicable, 'pilotId' => null];
		}

		$match = ($row['MATCHED_ACCESS_CODE'] ?? null) === null
			? AudienceMatch::NotMatched
			: AudienceMatch::Matched
		;

		return ['match' => $match, 'pilotId' => (int)$row['ID']];
	}

	/**
	 * @return string[]
	 * @throws AudienceUnavailableException
	 */
	private function audienceCodesOf(int $userId): array
	{
		try
		{
			$codes = $this->userAccessCodes->getCodes($userId);
		}
		catch (\Throwable $exception)
		{
			throw AudienceUnavailableException::accessCodesUnavailable($userId, $exception);
		}

		$relevant = [];
		foreach ($codes as $code)
		{
			$code = (string)$code;
			if (self::isSupportedAccessCode($code))
			{
				$relevant[$code] = $code;
			}
		}

		return array_values($relevant);
	}

	/**
	 * @param int[] $templateIds
	 * @return int[]
	 */
	private function normalizeTemplateIds(array $templateIds): array
	{
		$normalized = [];
		foreach ($templateIds as $templateId)
		{
			$templateId = (int)$templateId;
			if ($templateId > 0)
			{
				$normalized[$templateId] = $templateId;
			}
		}

		return array_values($normalized);
	}
}
