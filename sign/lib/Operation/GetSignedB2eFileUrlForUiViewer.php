<?php

namespace Bitrix\Sign\Operation;

use Bitrix\Main;
use Bitrix\Main\Web\Uri;
use Bitrix\Sign\Contract;

class GetSignedB2eFileUrlForUiViewer implements Contract\Operation
{
	public bool $ready = false;

	private const AJAX_PATH = '/bitrix/services/main/ajax.php';
	public const B2eFileSalt = 'b2eFileUiViewerSalt777';

	public function __construct(
		private array $data,
	)
	{
	}

	public function launch(): Main\Result
	{
		$data = [];
		$result = new Main\Result();

		if (!isset($this->data['ext']) || !is_string($this->data['ext']) || strtolower($this->data['ext']) !== 'pdf')
		{
			return $result->addError(new Main\Error('Cannot view not pdf file'));
		}

		if (!isset($this->data['url']) || !is_string($this->data['url']))
		{
			return $result->addError(new Main\Error('Invalid url'));
		}

		$downloadUri = new Uri($this->data['url']);
		if (
			$downloadUri->getScheme() !== ''
			|| $downloadUri->getHost() !== ''
			|| $downloadUri->getPath() !== self::AJAX_PATH
		)
		{
			return $result->addError(new Main\Error('Invalid url'));
		}

		$hashedUrl = hash('sha256', $this->data['url']);

		$signer = new Main\Security\Sign\Signer();
		$sign = $signer->sign($hashedUrl, self::B2eFileSalt);

		$uri = new Uri(self::AJAX_PATH);
		$uri->addParams([
			'action' => 'sign.api_v1.Document.B2eSignedFile.getFileUrlForUiViewer',
			'sign' => $sign,
			'url' => $this->data['url'],
		]);

		$data['url'] = $uri->getUri();
		$this->ready = true;
		$result->setData($data);

		return $result;
	}
}
