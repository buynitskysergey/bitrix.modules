<?php

namespace Bitrix\Sign\Controller\Internal;

use Bitrix\Main;
use Bitrix\Sign\Access\AccessController;
use Bitrix\Sign\Access\ActionDictionary;
use Bitrix\Sign\Document as DocumentCore;
use Bitrix\Sign\Helper\IterationHelper;
use Bitrix\Sign\Item;
use Bitrix\Sign\Item\Api\Document\Signing\SendInviteRequest;
use Bitrix\Sign\Proxy;
use Bitrix\Sign\Service;
use Bitrix\Sign\Type\DocumentScenario;

class Document extends \Bitrix\Sign\Controller\Controller
{
	public function getDefaultPreFilters(): array
	{
		return [
			new \Bitrix\Main\Engine\ActionFilter\Authentication(),
			new \Bitrix\Main\Engine\ActionFilter\Csrf(),
			new \Bitrix\Sign\Controller\ActionFilter\Extranet(),
		];
	}

	/**
	 * @param int $documentId
	 * @param string|null $memberHash Member hash.
	 * @return void
	 * @todo check usages, timeline?
	 */
	public function resendFileAction(string $documentId, ?string $memberHash = null)
	{
		$documentItem = Service\Container::instance()->getDocumentRepository()->getById((int)$documentId);
		if ($documentItem === null || !$this->canCurrentUserResendDocument($documentItem))
		{
			$this->addError(new Main\Error(
				Main\Localization\Loc::getMessage('SIGN_CONTROLLER_INTERNAL_DOCUMENT_ERROR_ACCESS_DENIED'),
				'ACCESS_DENIED',
			));

			return;
		}

		$document = DocumentCore::getById($documentId);
		if ($document)
		{
            if ($document->getDataValue('VERSION') == 2)
            {
                $response = Service\Container::instance()->getApiDocumentSigningService()->sendInvite(
                    new SendInviteRequest($document->getUid(), $memberHash),
                );
                if (!$response->isSuccess())
                {
                    foreach ($response->getErrors() as $error)
                    {
                        \Bitrix\Sign\Error::getInstance()->addErrorInstance($error);
                    }
                }
            }
            else
            {
                $result = Proxy::sendCommand('document.resend', [
                	'hash' => $document->getHash(),
                	'members' => [$document->getMemberByHash($memberHash)->toArray()],
                ]);

                if ($result)
                {
                    return $result;
                }
            }
		}
	}

	protected function getAccessController(): AccessController
	{
		return new AccessController((int)$this->getCurrentUser()?->getId());
	}

	/** Resending a file is available to whoever may read the document as well as to whoever may edit it. */
	private function canCurrentUserResendDocument(Item\Document $document): bool
	{
		$accessActions = DocumentScenario::isB2EScenario($document->scenario)
			? [ActionDictionary::ACTION_B2E_DOCUMENT_READ, ActionDictionary::ACTION_B2E_DOCUMENT_EDIT]
			: [ActionDictionary::ACTION_DOCUMENT_READ, ActionDictionary::ACTION_DOCUMENT_EDIT]
		;

		$accessController = $this->getAccessController();

		return IterationHelper::any(
			$accessActions,
			static fn(string $action): bool => $accessController->checkByItem($action, $document),
		);
	}
}
