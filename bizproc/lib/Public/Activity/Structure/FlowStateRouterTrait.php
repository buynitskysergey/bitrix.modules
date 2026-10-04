<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Public\Activity\Structure;

trait FlowStateRouterTrait
{
	private ?string $currentState = null;
	private ?string $nextState = null;

	private const IN_PORT_INIT = 'i0';
	private const IN_PORT_FIN = 'i1';
	private const IN_PORT_WAIT = 'i2';

	public function setNextState(string $nextState): void
	{
		$this->nextState = $nextState;
	}

	public function getNextStateName(): ?string
	{
		if ($this->nextState === null && $this->currentState === null)
		{
			return null;
		}

		// init state
		if ($this->currentState === null && $this->nextState)
		{
			$this->currentState = $this->nextState;

			return $this->nextState . self::LINK_DELIMITER . self::IN_PORT_INIT;
		}

		//wait state
		if ($this->currentState === $this->nextState)
		{
			$next = $this->nextState . self::LINK_DELIMITER . self::IN_PORT_WAIT;

			$this->nextState = null;

			return $next;
		}

		//fin state
		if ($this->currentState !== $this->nextState)
		{
			$next = $this->currentState . self::LINK_DELIMITER . self::IN_PORT_FIN;

			$this->currentState = null;

			return $next;
		}
	}
}
