<?php

declare(strict_types=1);

namespace Bitrix\Timeman\V2\Infrastructure\Controller;

use Bitrix\Main\Error;
use Bitrix\Main\Localization\Loc;
use Bitrix\Main\Validation\Rule\PositiveNumber;
use Bitrix\Timeman\V2\Internal\Service\ReportDiscussionService;

class ReportDiscussion extends BaseController
{
	private const ERROR_MESSAGE_KEYS = [
		ReportDiscussionService::ERROR_REPORT_NOT_FOUND => 'TIMEMAN_V2_REPORT_DISCUSSION_ERROR_REPORT_NOT_FOUND',
		ReportDiscussionService::ERROR_ACCESS_DENIED => 'TIMEMAN_V2_REPORT_DISCUSSION_ERROR_ACCESS_DENIED',
		ReportDiscussionService::ERROR_NO_MANAGER => 'TIMEMAN_V2_REPORT_DISCUSSION_ERROR_NO_MANAGER',
		ReportDiscussionService::ERROR_CHAT_CREATION => 'TIMEMAN_V2_REPORT_DISCUSSION_ERROR_CHAT_CREATION',
	];

	/**
	 * @ajaxAction timeman.V2.ReportDiscussion.discuss
	 */
	public function discussAction(
		#[PositiveNumber]
		int $reportId,
		ReportDiscussionService $service,
	): ?array
	{
		$actorId = (int)$this->getCurrentUser()->getId();

		$result = $service->discuss($reportId, $actorId);
		if (!$result->isSuccess())
		{
			$this->addErrors($this->localizeErrors($result->getErrors()));

			return null;
		}

		return $result->getData();
	}

	/**
	 * @param Error[] $errors
	 * @return Error[]
	 */
	private function localizeErrors(array $errors): array
	{
		return array_map(
			static function (Error $error): Error {
				$code = (string)$error->getCode();
				$messageKey = self::ERROR_MESSAGE_KEYS[$code] ?? null;
				if ($messageKey === null)
				{
					return $error;
				}

				return new Error((string)Loc::getMessage($messageKey), $code);
			},
			$errors,
		);
	}
}
