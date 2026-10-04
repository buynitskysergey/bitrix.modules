<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Integration\UI\AccessRights\V2\Structure\Action;

use Bitrix\UI\AccessRights\V2\Contract\AccessRightsBuilder\Provider\Structure\Action;
use Bitrix\UI\AccessRights\V2\Contract\AccessRightsBuilder\Provider\Structure\Configurator\RightItemConfigurator;
use Bitrix\UI\AccessRights\V2\Options\RightSection\RightItem;

/**
 * One matrix action per template permission. The id is the numeric permission code (as a string), so the
 * UI right id round-trips back to the permission id on save. The hint from the dictionary is pushed onto
 * the right item here, since the builder only invokes the control's and the action's configurators.
 */
final class TemplateAction implements Action, RightItemConfigurator
{
	public function __construct(
		private readonly string $id,
		private readonly string $title,
		private readonly string $hint = '',
	)
	{
	}

	public function getId(): string
	{
		return $this->id;
	}

	public function getTitle(): string
	{
		return $this->title;
	}

	public function configureRightItem(RightItem $rightItem): void
	{
		if ($this->hint !== '')
		{
			$rightItem->setHint($this->hint);
		}
	}
}
