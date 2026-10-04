<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\CallAssessment\Summary;

use Bitrix\Crm\ActivityTable;
use Bitrix\Crm\Copilot\AiCallSummary\Entity\AiCallSummaryTable;
use Bitrix\Crm\Copilot\AiQualityAssessment\Entity\AiQualityAssessmentTable;
use Bitrix\Main\ORM\Fields\ExpressionField;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\Type\Date;
use Bitrix\Main\Type\DateTime;

final class SummaryService
{
	private const WEEK_LENGTH_DAYS = 7;
	private const TOP_LIMIT = 5;

	private const ORDER_DESC = 'DESC';
	private const ORDER_ASC = 'ASC';

	/**
	 * @return array{
	 *     weekStart: Date,
	 *     weekEnd: Date,
	 *     totalCount: int,
	 *     avgAssessment: int,
	 *     topGood: list<array{activityId:int, subject:string, assessment:int, ownerTypeId:int, ownerId:int, jobId:int}>,
	 *     topBad: list<array{activityId:int, subject:string, assessment:int, ownerTypeId:int, ownerId:int, jobId:int}>,
	 * }
	 */
	public function buildSummary(Settings $settings, ?Date $referenceDate = null): array
	{
		$referenceDate ??= new Date();

		$weekStart = self::shiftDays($referenceDate, -(self::WEEK_LENGTH_DAYS - 1));
		$filterStart = self::dateToDateTime($weekStart);
		$filterEndExclusive = self::dateToDateTime(self::shiftDays($referenceDate, 1));

		$aggregate = $this->aggregate($filterStart, $filterEndExclusive);
		$totalCount = (int)$aggregate['CNT'];
		$avgAssessment = $totalCount > 0 ? (int)round((float)$aggregate['AVG_SCORE']) : 0;

		if ($totalCount > self::TOP_LIMIT * 2)
		{
			$topGood = $this->fetchTop($filterStart, $filterEndExclusive, self::ORDER_DESC, self::TOP_LIMIT);
			$topBad = $this->fetchTop($filterStart, $filterEndExclusive, self::ORDER_ASC, self::TOP_LIMIT);
		}
		else
		{
			$topGood = $this->fetchTop($filterStart, $filterEndExclusive, self::ORDER_DESC, $totalCount);
			$topBad = [];
		}

		return [
			'weekStart' => $weekStart,
			'weekEnd' => $referenceDate,
			'totalCount' => $totalCount,
			'avgAssessment' => $avgAssessment,
			'topGood' => $topGood,
			'topBad' => $topBad,
		];
	}

	/**
	 * @return array{CNT:int, AVG_SCORE:float}
	 */
	private function aggregate(DateTime $from, DateTime $toExclusive): array
	{
		$row = AiQualityAssessmentTable::query()
			->registerRuntimeField(new ExpressionField('CNT', 'COUNT(*)'))
			->registerRuntimeField(new ExpressionField('AVG_SCORE', 'AVG(%s)', ['ASSESSMENT']))
			->setSelect(['CNT', 'AVG_SCORE'])
			->where('USE_IN_RATING', true)
			->where('ACTIVITY_TYPE', AiQualityAssessmentTable::ACTIVITY_TYPE_CALL)
			->where('CREATED_AT', '>=', $from)
			->where('CREATED_AT', '<', $toExclusive)
			->fetch()
		;

		return [
			'CNT' => $row['CNT'] ?? 0,
			'AVG_SCORE' => $row['AVG_SCORE'] ?? 0.0,
		];
	}

	/**
	 * @return list<array{activityId:int, subject:string, assessment:int, ownerTypeId:int, ownerId:int, jobId:int}>
	 */
	private function fetchTop(DateTime $from, DateTime $toExclusive, string $direction, int $limit): array
	{
		if ($limit <= 0)
		{
			return [];
		}

		$rows = AiQualityAssessmentTable::query()
			->registerRuntimeField('ACTIVITY', new Reference(
				'ACTIVITY',
				ActivityTable::class,
				['=this.ACTIVITY_ID' => 'ref.ID'],
			))
			->registerRuntimeField('CALL_SUMMARY', new Reference(
				'CALL_SUMMARY',
				AiCallSummaryTable::class,
				['=this.ACTIVITY_ID' => 'ref.ACTIVITY_ID'],
			))
			->setSelect([
				'ACTIVITY_ID',
				'ASSESSMENT',
				'JOB_ID',
				'CALL_THEME' => 'CALL_SUMMARY.THEME',
				'ACTIVITY_OWNER_TYPE_ID' => 'ACTIVITY.OWNER_TYPE_ID',
				'ACTIVITY_OWNER_ID' => 'ACTIVITY.OWNER_ID',
			])
			->where('USE_IN_RATING', true)
			->where('ACTIVITY_TYPE', AiQualityAssessmentTable::ACTIVITY_TYPE_CALL)
			->where('CREATED_AT', '>=', $from)
			->where('CREATED_AT', '<', $toExclusive)
			->setOrder(['ASSESSMENT' => $direction, 'ID' => 'DESC'])
			->setLimit($limit)
			->fetchAll()
		;

		$result = [];
		foreach ($rows as $row)
		{
			$result[] = [
				'activityId' => (int)$row['ACTIVITY_ID'],
				'subject' => (string)($row['CALL_THEME'] ?? ''),
				'assessment' => (int)$row['ASSESSMENT'],
				'ownerTypeId' => (int)($row['ACTIVITY_OWNER_TYPE_ID'] ?? 0),
				'ownerId' => (int)($row['ACTIVITY_OWNER_ID'] ?? 0),
				'jobId' => (int)($row['JOB_ID'] ?? 0),
			];
		}

		return $result;
	}

	private static function shiftDays(Date $date, int $days): Date
	{
		if ($days === 0)
		{
			return clone $date;
		}

		$shifted = clone $date;
		$sign = $days > 0 ? '+' : '-';
		$shifted->add($sign . abs($days) . ' days');

		return $shifted;
	}

	private static function dateToDateTime(Date $date): DateTime
	{
		return DateTime::createFromTimestamp($date->getTimestamp());
	}
}
