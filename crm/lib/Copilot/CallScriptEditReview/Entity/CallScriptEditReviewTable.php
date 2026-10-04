<?php

declare(strict_types=1);

namespace Bitrix\Crm\Copilot\CallScriptEditReview\Entity;

use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\ORM\Data\DataManager;
use Bitrix\Main\ORM\Fields\IntegerField;

final class CallScriptEditReviewTable extends DataManager
{
	public static function getTableName(): string
	{
		return 'b_crm_ai_call_script_edit_review';
	}

	public static function getMap(): array
	{
		$fieldRepository = ServiceLocator::getInstance()->get('crm.model.fieldRepository');

		return [
			(new IntegerField('ID'))
				->configurePrimary()
				->configureAutocomplete()
			,
			(new IntegerField('ASSESSMENT_ID'))
				->configureRequired()
			,
			(new IntegerField('JOB_ID'))
				->configureRequired()
			,
			$fieldRepository->getCreatedTime('CREATED_AT'),
		];
	}
}
