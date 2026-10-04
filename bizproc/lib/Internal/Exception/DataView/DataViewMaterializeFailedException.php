<?php

declare(strict_types=1);

namespace Bitrix\Bizproc\Internal\Exception\DataView;

use Bitrix\Bizproc\Internal\Entity\DataView\DataView;
use Bitrix\Bizproc\Internal\Exception\Exception;

class DataViewMaterializeFailedException extends Exception
{
	public function __construct(private readonly DataView $view, \Throwable $previous)
	{
		parent::__construct(
			message: $previous->getMessage(),
			code: self::CODE_DATA_VIEW_MATERIALIZE,
			previous: $previous,
		);
	}

	public function getView(): DataView
	{
		return $this->view;
	}
}
