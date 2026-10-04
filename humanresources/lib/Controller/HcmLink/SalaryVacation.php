<?php

namespace Bitrix\HumanResources\Controller\HcmLink;

use Bitrix\HumanResources\Config\Feature;
use Bitrix\HumanResources\Engine\HcmLinkController;
use Bitrix\HumanResources\Item\HcmLink\Employee;
use Bitrix\HumanResources\Result\Service\HcmLink\JobServiceResult;
use Bitrix\HumanResources\Service\Container;
use Bitrix\HumanResources\Type\HcmLink\PayrollType;
use Bitrix\Main;
use Bitrix\Main\Engine\CurrentUser;

/**
 * Public salary/vacation flow for the mobile app on top of the tested HCM Link
 * transport (SalaryVacationApiService). Lives next to Mapper/Placement and follows
 * the same convention: humanresources.HcmLink.SalaryVacation.<action>.
 *
 *   companyList -> requestPin -> requestDocument -> getResult
 *
 * Access = feature gate (HcmLinkController) + own-check by Person.userId on every
 * action; getResult mirrors the {status, jobId, finishedAt} shape of Mapper.getJobStatus.
 */
class SalaryVacation extends HcmLinkController
{
	protected function processBeforeAction(Main\Engine\Action $action): bool
	{
		if (!Feature::instance()->isHcmLinkSalaryVacationApiAvailable())
		{
			$this->addError($this->makeAccessDeniedError());

			return false;
		}

		return parent::processBeforeAction($action);
	}

	protected function getDefaultPreFilters(): array
	{
		$filters = parent::getDefaultPreFilters();
		foreach ($filters as $index => $filter)
		{
			if ($filter instanceof Main\Engine\ActionFilter\HttpMethod)
			{
				$filters[$index] = new Main\Engine\ActionFilter\HttpMethod(
					[Main\Engine\ActionFilter\HttpMethod::METHOD_POST],
				);
			}
		}

		return $filters;
	}

	public function companyListAction(): array
	{
		$userId = (int)CurrentUser::get()->getId();

		return Container::getHcmLinkSalaryVacationApiService()->getOwnedCompanies($userId);
	}

	public function requestPinAction(int $companyId, int $employeeId, string $type): array
	{
		if ($this->getOwnEmployee($companyId, $employeeId) === null)
		{
			return [];
		}

		$payrollType = $this->resolvePayrollType($type);
		if ($payrollType === null)
		{
			$this->addError(new Main\Error('Unknown salary/vacation type', 'INVALID_TYPE'));

			return [];
		}

		return $this->taskIdResponse(
			Container::getHcmLinkSalaryVacationApiService()->requestPin(
				(int)CurrentUser::get()->getId(),
				$companyId,
				$employeeId,
				$payrollType,
			),
		);
	}

	public function requestDocumentAction(
		int $companyId,
		int $employeeId,
		string $type,
		string $pin,
		?array $period = null,
	): array
	{
		$employee = $this->getOwnEmployee($companyId, $employeeId);
		if ($employee === null)
		{
			return [];
		}

		$payrollType = $this->resolvePayrollType($type);
		if ($payrollType === null)
		{
			$this->addError(new Main\Error('Unknown salary/vacation type', 'INVALID_TYPE'));

			return [];
		}

		$month = isset($period['month']) ? (int)$period['month'] : null;
		$year = isset($period['year']) ? (int)$period['year'] : null;

		return $this->taskIdResponse(
			Container::getHcmLinkSalaryVacationApiService()
				->request(
					(int)CurrentUser::get()->getId(),
					$companyId,
					$employeeId,
					$payrollType,
					$pin,
					$month,
					$year,
				),
		);
	}

	public function getResultAction(int $taskId, int $companyId, int $employeeId): array
	{
		$employee = $this->getOwnEmployee($companyId, $employeeId);
		if ($employee === null)
		{
			return [];
		}

		return Container::getHcmLinkSalaryVacationApiService()->getStatus($taskId, $companyId, $employee);
	}

	private function getOwnEmployee(int $companyId, int $employeeId): ?Employee
	{
		$userId = (int)CurrentUser::get()->getId();
		$employee = Container::getHcmLinkSalaryVacationApiService()
			->getOwnEmployee($userId, $companyId, $employeeId)
		;
		if ($employee !== null)
		{
			return $employee;
		}

		$this->addError($this->makeAccessDeniedError());

		return null;
	}

	private function resolvePayrollType(string $type): ?PayrollType
	{
		return match (mb_strtolower($type))
		{
			'salary' => PayrollType::SALARY,
			'vacation' => PayrollType::VACATION,
			default => null,
		};
	}

	private function taskIdResponse(Main\Result|JobServiceResult $result): array
	{
		if ($result instanceof JobServiceResult)
		{
			return ['taskId' => $result->job->id];
		}

		$this->addErrors($result->getErrors());

		return [];
	}
}
