<?php

namespace Bitrix\Crm\Copilot\CallAssessment\Entity;

use Bitrix\Crm\Service\Container;
use Bitrix\Main;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\ORM\Fields\Relations\Reference;
use Bitrix\Main\ORM\Fields\Validators\LengthValidator;
use Bitrix\Main\ORM\Fields\Validators\RangeValidator;
use Bitrix\Main\ORM\Query\Join;

class CopilotCallAssessmentCriteriaTable extends Main\ORM\Data\DataManager
{
	public static function getTableName(): string
	{
		return 'b_crm_copilot_call_assessment_criteria';
	}

	public static function getMap(): array
	{
		Container::getInstance()->getLocalization()->loadMessages();

		$fieldRepository = ServiceLocator::getInstance()->get('crm.model.fieldRepository');

		return [
			$fieldRepository->getId(),
			$fieldRepository->getTitle()
				->configureRequired()
				->addValidator(new LengthValidator(1, 255))
			,
			(new Main\ORM\Fields\IntegerField('ASSESSMENT_ID'))
				->addValidator(new RangeValidator(1))
				->configureRequired()
			,
			(new Main\ORM\Fields\StringField('DESCRIPTION'))
				->configureSize(600)
				->addValidator(new LengthValidator(1, 600))
				->configureRequired()
			,
			new Main\ORM\Fields\IntegerField('SORT'),
			$fieldRepository
				->getCreatedBy('CREATED_BY_ID')
				->configureDefaultValue(static fn() => Container::getInstance()->getContext()->getUserId())
			,
			$fieldRepository
				->getUpdatedBy('UPDATED_BY_ID')
				->configureDefaultValue(static fn() => Container::getInstance()->getContext()->getUserId())
			,
			$fieldRepository->getCreatedTime('CREATED_AT'),
			$fieldRepository->getUpdatedTime('UPDATED_AT'),
			new Reference(
				'ASSESSMENT',
				CopilotCallAssessmentTable::class,
				Join::on('this.ASSESSMENT_ID', 'ref.ID'),
			),
		];
	}
}
