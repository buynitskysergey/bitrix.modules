<?php

namespace Bitrix\Sign\Controllers\V1\B2e;

use Bitrix\Main;
use Bitrix\Main\Context;
use Bitrix\Sign\Config\Storage;
use Bitrix\Sign\Engine\Controller;
use Bitrix\Sign\Service;

class Member extends Controller
{
	public function getDefaultPreFilters(): array
	{
		return [
			new Main\Engine\ActionFilter\Cors()
		];
	}

	/**
	 * @param string $uid
	 * @param string $token
	 * @return array
	 */
	public function getAvatarAction(string $uid, string $token): array
	{
		if (
			!Storage::instance()->isB2eAvailable()
			|| empty(Storage::instance()->getClientToken())
		)
		{
			$this->addAvatarAccessDeniedError();
			return [];
		}

		try
		{
			$unsignedUid = (new Main\Security\Sign\TimeSigner())
				->setKey(hash('sha256', Storage::instance()->getClientToken(), true))
				->unsign($token, md5(Storage::instance()->getClientToken() . 'BITRIX_SIGN_SERVICE'))
			;
			if ($unsignedUid !== $uid)
			{
				$this->addAvatarAccessDeniedError();
				return [];
			}
		}
		catch (Main\Security\Sign\BadSignatureException $e)
		{
			$this->addAvatarAccessDeniedError();
			return [];
		}

		$file = Service\Container::instance()
			->getSignMemberUserService()
			->getAvatarByMemberUid($uid)
		;

		if (!$file || !$file->getId() || empty($file->getBase64Content()))
		{
			$this->addError(new Main\Error('No avatar'));
			Context::getCurrent()->getResponse()->setStatus(404);
			return [];
		}

		$base64Content = $file->getBase64Content();

		return [
			'file' => [
				'type' => $file?->getType() ?? '',
				'base64' => $base64Content ?? ''
			],
		];
	}

	/**
	 * Deliberately not the inherited addAccessDeniedError(): this endpoint is anonymous
	 * (token-based, CORS only) and consumed by the sign service, so it must stay on HTTP 200.
	 */
	private function addAvatarAccessDeniedError(): void
	{
		$this->addError(new Main\Error('Access denied.'));
	}
}