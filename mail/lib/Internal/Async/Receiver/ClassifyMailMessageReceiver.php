<?php

declare(strict_types=1);

namespace Bitrix\Mail\Internal\Async\Receiver;

use Bitrix\Main;
use Bitrix\Main\Messenger\Entity\MessageInterface;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\NoLoggableException;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\RecoverableMessageException;
use Bitrix\Main\Messenger\Internals\Exception\Receiver\UnprocessableMessageException;
use Bitrix\Main\Messenger\Receiver\AbstractReceiver;
use Bitrix\Mail\Integration\AI\MessageClassifierFactory;
use Bitrix\Mail\Internal\Async\Message\ClassifyMailMessage;
use Bitrix\Mail\Internal\Service\Message\ClassificationMessageRepository;
use Bitrix\Mail\Internal\Service\Message\ClassificationOutcome;
use Bitrix\Mail\Internal\Service\Message\ClassificationSettings;
use Bitrix\Mail\Internal\Service\Message\ClassificationTextBuilder;
use Bitrix\Mail\Internal\Service\Message\MessageClassifierInterface;

/**
 * Runs the outgoing classify request outside mail sync. The letter is re-read here, because between the
 * queue and the request it may be gone or its gate switched off.
 *
 * The live mailbox-message pair is not checked here: linkMessage may not have run yet when the job
 * starts. The pair is checked where the label is applied - MessageClassifier::messageStillExists.
 */
class ClassifyMailMessageReceiver extends AbstractReceiver
{
	/**
	 * The worst case for one letter is 90 s (HttpClient defaults), so the budget is checked between
	 * letters and a pass costs at most 15 + 90 s of the request_terminate_timeout.
	 */
	private const PASS_TIME_BUDGET = 15;

	private ?ClassificationSettings $settings = null;

	private ?ClassificationMessageRepository $repository = null;

	private ?float $passStartedAt = null;

	/**
	 * Process-wide singleton: the collaborators are dropped so the per-mailbox cache does not outlive a
	 * pass.
	 */
	public function run(): void
	{
		$this->settings = null;
		$this->repository = null;
		$this->passStartedAt = null;

		parent::run();
	}

	protected function process(MessageInterface $message): void
	{
		if (!$message instanceof ClassifyMailMessage)
		{
			throw new UnprocessableMessageException($message);
		}

		if ($this->isTimeBudgetSpent())
		{
			throw new RecoverableMessageException(
				sprintf('classify: the pass time budget of %d s is spent', self::PASS_TIME_BUDGET),
			);
		}

		if (!Main\Loader::includeModule('ai'))
		{
			return;
		}

		$settings = $this->getSettings();
		if (!$settings->isAutoClassifyEnabled())
		{
			return;
		}

		if (!$settings->isAutoClassifyAllowedForMailbox($message->mailboxId))
		{
			return;
		}

		$row = $this->getMessageRepository()->loadForClassification($message->mailboxId, $message->messageId);
		if ($row === null)
		{
			return;
		}

		$text = ClassificationTextBuilder::buildFrom($row);
		if ($text === '')
		{
			return;
		}

		$outcome = $this->getClassifier()->scheduleClassification($message->mailboxId, $message->messageId, $text);

		// NoLoggableException: the retry is spent either way, the class only keeps three records per
		// letter out of the exception log.
		if ($outcome === ClassificationOutcome::TemporaryFailure)
		{
			throw new NoLoggableException(
				sprintf(
					'classify: the engine did not take the letter mailboxId=%d messageId=%d',
					$message->mailboxId,
					$message->messageId,
				),
			);
		}
	}

	/**
	 * RecoverableMessageException gives the leftovers of the batch back without spending a retry; the
	 * first letter of a pass always runs.
	 */
	private function isTimeBudgetSpent(): bool
	{
		$now = $this->currentTime();

		$this->passStartedAt ??= $now;

		return ($now - $this->passStartedAt) >= self::PASS_TIME_BUDGET;
	}

	protected function currentTime(): float
	{
		return microtime(true);
	}

	protected function getSettings(): ClassificationSettings
	{
		return $this->settings ??= new ClassificationSettings();
	}

	protected function getMessageRepository(): ClassificationMessageRepository
	{
		return $this->repository ??= new ClassificationMessageRepository();
	}

	protected function getClassifier(): MessageClassifierInterface
	{
		return MessageClassifierFactory::getInstance();
	}
}
