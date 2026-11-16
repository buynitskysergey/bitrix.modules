<?php

declare(strict_types=1);

namespace Bitrix\Note\Infrastructure\ExternalAccess\Controller\ActionFilter;

use Bitrix\Main\Engine\Action;
use Bitrix\Main\Engine\ActionFilter\Base;
use Bitrix\Main\Error;
use Bitrix\Main\Event;
use Bitrix\Main\EventResult;
use Bitrix\Main\Localization\Loc;
use Bitrix\Note\Infrastructure\Controller\ErrorCodeDictionary;
use Bitrix\Note\Infrastructure\ExternalAccess\Controller\BaseController;
use Bitrix\Note\Infrastructure\ExternalAccess\Controller\ExternalAccessContext;
use Bitrix\Note\Internal\Service\ExternalAccess\PublicVisitorContextResolver;

/**
 * The pre-filter of the public actions that carry no token: the file paths.
 *
 * A token cannot be there. The address of a picture is substituted into `<img src>` and the download
 * one into a plain link, and a browser puts such an address into the history, into the referer of the
 * next request and into the log of the web server - the secret of the link would leak by the mere
 * act of showing the page. So the link is recognised from what the request does name - the
 * file or the document - plus the marks the visitor's own session already holds.
 *
 * The chain itself belongs to PublicVisitorContextResolver, shared with the verdict of the attachment
 * connector, and it asks both marks: the context one and the password confirmation. This
 * filter only decides which identifier of the request the chain starts from, and hands the resolved
 * link to the controller exactly as ExternalAccessLink does for the token paths.
 *
 * One refusal for everything, the same as on the token paths: outside the scope, disabled, expired,
 * password not named yet - nothing here tells "there was none" from "it was closed".
 */
class ExternalAccessVisitorContext extends Base
{
	public function __construct(
		private readonly PublicVisitorContextResolver $contextResolver = new PublicVisitorContextResolver(),
	)
	{
		parent::__construct();
	}

	public function onBeforeAction(Event $event): ?EventResult
	{
		$action = $event->getParameter('action');
		if (!$action instanceof Action)
		{
			return $this->denied();
		}

		$controller = $action->getController();
		if (!$controller instanceof BaseController)
		{
			return $this->denied();
		}

		$request = $controller->getRequest();
		$fileId = (int)$request->get('fileId');
		$documentId = (int)$request->get('documentId');

		// A file names its document by itself, and the document is what the right is about, so the
		// file identifier wins when both are there: an action that has a file cannot be widened by
		// adding a documentId of some other knowledge base to the request.
		$link = $fileId > 0
			? $this->contextResolver->forFile($fileId)
			: $this->contextResolver->forDocument($documentId)
		;

		if ($link === null)
		{
			return $this->denied();
		}

		// The chain started from a file for the streaming actions, and the row it walked through is
		// handed on so the action does not read it a second time. It widens nothing: the scope is the
		// resolved link, and the action checks the document against it exactly as before.
		$controller->setExternalAccessContext(new ExternalAccessContext(
			$link->linkRecordId,
			$link->collectionId,
			$link->accessLevel,
			$this->contextResolver->getResolvedFileLink(),
		));

		return null;
	}

	private function denied(): EventResult
	{
		$code = ErrorCodeDictionary::EXTERNAL_ACCESS_DENIED;
		$this->addError(new Error(Loc::getMessage($code), $code));

		return new EventResult(EventResult::ERROR, null, null, $this);
	}
}
