<?php

declare(strict_types=1);

namespace Bitrix\MessageService\Infrastructure\Component\CustomTemplate\ListPageGrid\Settings;

use Bitrix\Main\Grid\Settings;

final class CustomTemplateGridSettings extends Settings
{
	private const ID_PREFIX = 'messageservice_custom_template_list_';

	private string $zone;
	private string $scene;
	private string $targetId;

	public function __construct(array $params)
	{
		$this->zone = (string)($params['ZONE'] ?? '');
		$this->scene = (string)($params['SCENE'] ?? '');
		$this->targetId = (string)($params['TARGET_ID'] ?? '');

		// Grid id is the identity of the saved filter/grid state, so it must differ per
		// (zone, scene, target): otherwise contexts like a Lead card and a Contact card
		// share one stored filter. scene/target carry chars unsafe for a DOM id and a
		// b_user_option key (dots, colons), so the variable part is a deterministic md5.
		$params['ID'] = self::ID_PREFIX . $this->zone . '_' . md5($this->scene . ':' . $this->targetId);

		parent::__construct($params);
	}

	public function getZone(): string
	{
		return $this->zone;
	}

	public function getScene(): string
	{
		return $this->scene;
	}

	public function getTargetId(): string
	{
		return $this->targetId;
	}
}
