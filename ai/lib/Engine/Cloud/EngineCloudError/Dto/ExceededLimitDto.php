<?php declare(strict_types=1);

namespace Bitrix\AI\Engine\Cloud\EngineCloudError\Dto;

use Bitrix\AI\Enum\VibePlusLimitState;
use Bitrix\Main\Type\Contract\Arrayable;

class ExceededLimitDto implements Arrayable
{
	public function __construct(
		public readonly bool $showSliderWithMsg,
		public readonly string $sliderCode,
		public readonly string $errorCode,
		public readonly string $msgForIm,
		public readonly bool $isAvailableBaas,
		public readonly ?VibePlusLimitState $vibePlusLimitState = null,
	)
	{
	}

	public function toArray(): array
	{
		$data = [
			'showSliderWithMsg' => $this->showSliderWithMsg,
			'sliderCode' => $this->sliderCode,
			'errorCode' => $this->errorCode,
			'msgForIm' => $this->msgForIm,
			'isAvailableBaas' => $this->isAvailableBaas,
		];

		if ($this->vibePlusLimitState !== null)
		{
			$data['vibePlusLimitState'] = $this->vibePlusLimitState->name;
		}

		return $data;
	}
}
