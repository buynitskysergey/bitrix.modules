<?php

namespace Bitrix\Bizproc\Internal\Entity\Activity\Result;

use Bitrix\Bizproc\Internal\Entity\Activity\ActivityAiDescriptionSource;
use Bitrix\Bizproc\Internal\Entity\Activity\SettingCollection;
use Bitrix\Main\Result;

class ActivityAiDescriptionResult extends Result
{
	/**
	 * @param list<string> $skippedSettings Names of activity properties that cannot be expressed
	 *     in the settings schema.
	 */
	public function __construct(
		public readonly string $code,
		public readonly SettingCollection $settings,
		public readonly ActivityAiDescriptionSource $source = ActivityAiDescriptionSource::Manual,
		public readonly array $skippedSettings = [],
	)
	{
		parent::__construct();
	}
}