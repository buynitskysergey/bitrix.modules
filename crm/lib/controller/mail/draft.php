<?php

declare(strict_types=1);

namespace Bitrix\Crm\Controller\Mail;

use Bitrix\Crm\Integration\Mail\Draft as MailDraft;
use Bitrix\Crm\Service\Container;
use Bitrix\Intranet;
use Bitrix\Mail\Internal\Service\Draft\DraftService;
use Bitrix\Mail\Internals\DraftTable;
use Bitrix\Main\Engine\ActionFilter;
use Bitrix\Main\Engine\Controller;
use Bitrix\Main\Engine\CurrentUser;
use Bitrix\Main\Error;
use Bitrix\Main\Loader;
use Bitrix\Main\Result;

final class Draft extends Controller
{
	private const ERROR_FEATURE_DISABLED = 'FEATURE_DISABLED';
	private const ERROR_ENTITY_NOT_FOUND = 'CRM_ENTITY_NOT_FOUND';

	private ?DraftService $draftService = null;

	protected function getDefaultPreFilters(): array
	{
		$filters = parent::getDefaultPreFilters();
		if (Loader::includeModule('intranet'))
		{
			$filters[] = new Intranet\ActionFilter\IntranetUser();
		}

		return $filters;
	}

	public function configureActions(): array
	{
		$filters = [new ActionFilter\HttpMethod([ActionFilter\HttpMethod::METHOD_POST])];

		return [
			'getByContext' => ['+prefilters' => $filters],
			'save' => ['+prefilters' => $filters],
			'deleteCurrent' => ['+prefilters' => $filters],
		];
	}

	public function getByContextAction(int $entityTypeId, int $entityId): ?array
	{
		if (!$this->checkFeatureBoundary())
		{
			return null;
		}

		// An entity that is gone or closed for the user is answered as "no draft": a restore must not
		// break the compose form it is called from.
		if (!$this->checkCrmBoundary($entityTypeId, $entityId, true))
		{
			return ['draft' => null];
		}

		return $this->execute(
			fn(): Result => $this->getDraftService()->getByCrmContext($this->getUserId(), $entityTypeId, $entityId),
		);
	}

	public function saveAction(
		int $entityTypeId,
		int $entityId,
		array $snapshot,
		?int $draftId = null,
		?int $revision = null,
	): ?array
	{
		if (!$this->checkBoundary($entityTypeId, $entityId))
		{
			return null;
		}

		return $this->execute(
			fn(): Result => $this->getDraftService()->save(
				userId: $this->getUserId(),
				contextType: DraftTable::CONTEXT_CRM,
				draftId: $draftId,
				revision: $revision,
				snapshotData: $snapshot,
				crmEntityTypeId: $entityTypeId,
				crmEntityId: $entityId,
			),
		);
	}

	public function deleteCurrentAction(int $entityTypeId, int $entityId): ?array
	{
		if (!$this->checkBoundary($entityTypeId, $entityId))
		{
			return null;
		}

		$lookup = $this->execute(
			fn(): Result => $this->getDraftService()->getByCrmContext(
				$this->getUserId(),
				$entityTypeId,
				$entityId,
			),
		);
		if ($lookup === null)
		{
			return null;
		}

		$draft = $lookup['draft'] ?? null;
		if ($draft === null)
		{
			return ['deleted' => false];
		}

		return $this->execute(
			fn(): Result => $this->getDraftService()->delete(
				$this->getUserId(),
				(int)$draft['id'],
				DraftTable::CONTEXT_CRM,
			),
		);
	}

	private function checkBoundary(int $entityTypeId, int $entityId): bool
	{
		return $this->checkFeatureBoundary() && $this->checkCrmBoundary($entityTypeId, $entityId);
	}

	/**
	 * An unavailable feature is always reported: it is a wrong call, not a state of the CRM entity.
	 */
	private function checkFeatureBoundary(): bool
	{
		if (!MailDraft::isAvailable())
		{
			$this->addError(new Error('Feature is unavailable.', self::ERROR_FEATURE_DISABLED));

			return false;
		}

		return true;
	}

	/**
	 * A missing entity and a closed one are refused by the same error on purpose: a separate code for
	 * either of them would let anyone tell existing CRM entities from missing ones by trying ids.
	 */
	private function checkCrmBoundary(int $entityTypeId, int $entityId, bool $hideFailure = false): bool
	{
		if (
			!$this->crmEntityExists($entityTypeId, $entityId)
			|| !\CCrmActivity::checkUpdatePermission($entityTypeId, $entityId)
		)
		{
			if (!$hideFailure)
			{
				$this->addError(new Error('CRM entity is unavailable.', self::ERROR_ENTITY_NOT_FOUND));
			}

			return false;
		}

		return true;
	}

	/**
	 * Autosave hits this on every keystroke batch, so existence stays a single lightweight SELECT.
	 * The factory is kept as the supported-type gate and as the only source of the entity data class:
	 * dynamic types resolve their class at runtime, so the table must never be guessed from the type.
	 * The query must not filter by permissions: the update verdict belongs to CCrmActivity, which is the
	 * gate the rest of the activity code shares, and a filtered query would answer a different question.
	 */
	private function crmEntityExists(int $entityTypeId, int $entityId): bool
	{
		if ($entityId <= 0)
		{
			return false;
		}

		$factory = Container::getInstance()->getFactory($entityTypeId);
		if ($factory === null)
		{
			return false;
		}

		$dataClass = $factory->getDataClass();

		return (bool)$dataClass::query()
			->setSelect(['ID'])
			->where('ID', $entityId)
			->setLimit(1)
			->fetch()
		;
	}

	private function execute(callable $action): ?array
	{
		$result = $action();
		if (!$result->isSuccess())
		{
			foreach ($result->getErrors() as $error)
			{
				$this->addError(new Error('Draft operation failed.', (string)$error->getCode()));
			}

			return null;
		}

		return $result->getData();
	}

	/**
	 * Built here and not injected into the *Action() parameters on purpose: the service comes from the
	 * optional mail module, and an auto-wired parameter would be constructed before
	 * checkFeatureBoundary() includes that module, turning the FEATURE_DISABLED contract into a fatal
	 * error.
	 */
	private function getDraftService(): DraftService
	{
		return $this->draftService ??= new DraftService();
	}

	private function getUserId(): int
	{
		// getCurrentUser() is filled by the engine; a directly created controller falls back on its own.
		return (int)($this->getCurrentUser() ?? CurrentUser::get())->getId();
	}
}
