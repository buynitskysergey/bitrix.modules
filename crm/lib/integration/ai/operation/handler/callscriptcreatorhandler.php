<?php

declare(strict_types=1);

namespace Bitrix\Crm\Integration\AI\Operation\Handler;

use Bitrix\Crm\Copilot\CallAssessment\CallAssessmentItem;
use Bitrix\Crm\Copilot\CallAssessment\Controller\CopilotCallAssessmentController;
use Bitrix\Crm\Copilot\CallAssessment\CriteriaWriter;
use Bitrix\Crm\Copilot\PullManager;
use Bitrix\Crm\Integration\AI\AIManager;
use Bitrix\Crm\Integration\AI\Dto\Scoring\CallScriptCreatorPayload;
use Bitrix\Crm\Integration\AI\Model\QueueTable;
use Bitrix\Crm\Service\Container;
use Bitrix\Main\Application;

final class CallScriptCreatorHandler
{
	public function __construct(
		private readonly CallScriptCreatorPayload $payload,
		private readonly int $assessmentId,
		private readonly int $userId,
	) {}

	public function enrichScript(): bool
	{
		if ($this->assessmentId <= 0)
		{
			return false;
		}

		$entity = CopilotCallAssessmentController::getInstance()->getById($this->assessmentId);
		if (!$entity)
		{
			AIManager::logger()->error(
				'{date}: {class}: assessment not found: {assessmentId}',
				['class' => self::class, 'assessmentId' => $this->assessmentId],
			);

			return false;
		}

		$criteria = $this->prepareCriteria();
		if ($criteria === [])
		{
			AIManager::logger()->error(
				'{date}: {class}: no valid criteria generated for assessment {assessmentId}',
				['class' => self::class, 'assessmentId' => $this->assessmentId],
			);

			return false;
		}

		$item = CallAssessmentItem::createFromEntity($entity)
			->setStatus(QueueTable::EXECUTION_STATUS_SUCCESS)
		;

		if (!empty($this->payload->name))
		{
			$item->setTitle($this->payload->name);
		}

		if (!empty($this->payload->description))
		{
			$item->setDescription($this->payload->description);
		}

		$connection = Application::getConnection();
		$connection->startTransaction();

		try
		{
			$savedCount = $this->saveCriteria($this->assessmentId, $criteria);
			if ($savedCount === 0)
			{
				$connection->rollbackTransaction();
				AIManager::logger()->error(
					'{date}: {class}: failed to save any criterion for assessment {assessmentId}',
					['class' => self::class, 'assessmentId' => $this->assessmentId],
				);

				return false;
			}

			$updateResult = $this->withUserContext(
				fn() => CopilotCallAssessmentController::getInstance()->update($this->assessmentId, $item),
			);

			if (!$updateResult->isSuccess())
			{
				$connection->rollbackTransaction();
				AIManager::logger()->error(
					'{date}: {class}: enrich script failed: {errors}',
					['class' => self::class, 'errors' => $updateResult->getErrors()],
				);

				return false;
			}

			$connection->commitTransaction();
		}
		catch (\Throwable $e)
		{
			$connection->rollbackTransaction();
			AIManager::logger()->error(
				'{date}: {class}: enrich script exception: {message}',
				['class' => self::class, 'message' => $e->getMessage()],
			);

			return false;
		}

		$this->sendPullEvent($this->assessmentId);

		return true;
	}

	/**
	 * @return array<array{title: string, description: string}>
	 */
	private function prepareCriteria(): array
	{
		$criteria = [];

		foreach ($this->payload->newCriteria as $criterion)
		{
			$title = trim($criterion->name ?? '');
			$description = trim($criterion->description ?? '');
			if ($title === '' || $description === '')
			{
				continue;
			}

			$criteria[] = [
				'title' => $title,
				'description' => $description,
			];
		}

		return $criteria;
	}

	/**
	 * @param array<array{title: string, description: string}> $criteria
	 * @return int number of criteria actually persisted
	 */
	private function saveCriteria(int $assessmentId, array $criteria): int
	{
		$results = $this->withUserContext(
			fn() => (new CriteriaWriter())->append($assessmentId, $criteria),
		);

		$savedCount = 0;
		foreach ($results as $result)
		{
			if ($result->isSuccess())
			{
				$savedCount++;

				continue;
			}

			AIManager::logger()->error(
				'{date}: {class}: Failed to save generated criterion: {errors}',
				[
					'class' => self::class,
					'errors' => $result->getErrors(),
				],
			);
		}

		return $savedCount;
	}

	private function sendPullEvent(int $assessmentId): void
	{
		(new PullManager())->sendCreateCompletePullEvent($this->userId, $assessmentId);
	}

	private function withUserContext(callable $callable): mixed
	{
		if ($this->userId <= 0)
		{
			return $callable();
		}

		$context = Container::getInstance()->getContext();
		$previousUserId = $context->getUserId();
		$context->setUserId($this->userId);

		try
		{
			return $callable();
		}
		finally
		{
			$context->setUserId($previousUserId);
		}
	}
}
