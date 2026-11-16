<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\ExternalAccess\Controller;

use Bitrix\Main\Error;
use Bitrix\Main\HttpRequest;
use Bitrix\Main\Localization\Loc;
use Bitrix\Note\Infrastructure\Controller\ErrorCodeDictionary;
use Bitrix\Note\Internal\Service\ExternalAccess\ExternalAccessLifecycleService;
use Bitrix\Note\Internal\Service\ExternalAccess\PasswordProtectionService;
use Bitrix\Note\Internal\Service\ExternalAccess\PasswordProtectionVerdict;
use Bitrix\Note\Internal\Service\ExternalAccess\VisitorSessionService;

/**
 * The confirmation of the password of a public link.
 *
 * This is the only action of the public circuit that runs while the confirmation is missing: the
 * pre-filter lets it in by its full name and asks the same question of every other action. The
 * order inside is fixed - brute-force protection, then the comparison, then the mark - and the
 * comparison itself happens nowhere here: the hash never leaves the lifecycle service.
 *
 * CloseSession is absent from the set of filters on purpose: the confirmation is written into the
 * session, and closing it early would drop the mark that has just been made.
 */
class PasswordController extends BaseController
{
	public function __construct(
		?HttpRequest $request = null,
		private readonly ExternalAccessLifecycleService $lifecycleService = new ExternalAccessLifecycleService(),
		private readonly PasswordProtectionService $protectionService = new PasswordProtectionService(),
		private readonly VisitorSessionService $visitorSessionService = new VisitorSessionService(),
	)
	{
		parent::__construct($request);
	}

	/**
	 * @return array{confirmed: true}|null
	 */
	public function confirmAction(
		string $password,
		?string $captchaCode = null,
		?string $captchaWord = null,
	): ?array
	{
		$context = $this->getExternalAccessContext();
		$linkRecordId = $context->getLinkRecordId();

		// A demand for the captcha always arrives with the code to solve it: the protection lets the
		// attempt through rather than demanding a check the visitor could never pass.
		$verdict = $this->protectionService->attempt($linkRecordId);
		if (
			$verdict->captchaRequired
			&& !$this->protectionService->checkCaptcha($captchaCode, $captchaWord)
		)
		{
			return $this->refuse($verdict);
		}

		// The verdict travels on to the mark instead of being reduced to yes-or-no here: the mark is
		// bound to the version of the secret this comparison passed against, and a link whose password
		// has just been taken away ends the entrance instead of failing it (see checkPassword).
		$verified = $this->lifecycleService->checkPassword($context->getCollectionId(), $password);
		if (!$verified->isAllowed)
		{
			return $this->refuse($this->protectionService->verdictAfterMiss($linkRecordId));
		}

		if (!$this->visitorSessionService->markConfirmed($linkRecordId, $verified))
		{
			// There is nothing to bind the confirmation to, and a confirmation that was not stored
			// must not look like one that was: the visitor is asked again.
			return $this->refuse(PasswordProtectionVerdict::allowed());
		}

		$this->protectionService->onSuccess($linkRecordId);

		return ['confirmed' => true];
	}

	/**
	 * One and the same refusal for a wrong password and for a demand of the captcha: the visitor is
	 * told what to do next and nothing about the link itself.
	 */
	private function refuse(PasswordProtectionVerdict $verdict): null
	{
		$customData = ['captchaRequired' => $verdict->captchaRequired];
		if ($verdict->captchaCode !== null)
		{
			$customData['captchaCode'] = $verdict->captchaCode;
		}

		$this->addError(new Error(
			Loc::getMessage(ErrorCodeDictionary::EXTERNAL_ACCESS_PASSWORD_REQUIRED),
			ErrorCodeDictionary::EXTERNAL_ACCESS_PASSWORD_REQUIRED,
			$customData,
		));

		return null;
	}
}
