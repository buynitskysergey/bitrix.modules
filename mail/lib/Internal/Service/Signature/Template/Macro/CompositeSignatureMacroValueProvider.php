<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Service\Signature\Template\Macro;

use Bitrix\Mail\Internal\Service\Signature\Template\SignatureTemplateContext;

final class CompositeSignatureMacroValueProvider implements SignatureMacroValueProvider
{
	/** @var SignatureMacroValueProvider[] */
	private array $providers;

	/** @param iterable<SignatureMacroValueProvider> $providers */
	public function __construct(iterable $providers)
	{
		$this->providers = [...$providers];
	}

	public function getValues(SignatureTemplateContext $context, array $ids = []): array
	{
		$values = [];
		foreach ($this->providers as $provider)
		{
			foreach ($provider->getValues($context, $ids) as $id => $value)
			{
				if (array_key_exists($id, $values))
				{
					throw new \LogicException("A signature macro value for {$id} is already provided.");
				}

				$values[$id] = $value;
			}
		}

		return $values;
	}
}
