<?php

declare(strict_types=1);

namespace Bitrix\Note\Internal\Service\ExternalAccess;

use Bitrix\Main\Application;
use Bitrix\Main\Context;
use Bitrix\Main\Data\Storage\PersistentStorageInterface;
use Bitrix\Main\DI\ServiceLocator;
use Bitrix\Main\HttpRequest;
use Throwable;

/**
 * Brute-force protection of the password of a public link.
 *
 * The counter is keyed by the pair "link and address", and both halves are mandatory. Without the
 * link, a brute-force against one link would close the entrance to every other link opened from
 * the same address - an office behind a single NAT. Without the address, anyone who knows the
 * address of a link would close its entrance to all of its visitors.
 *
 * The identity of the visitor is deliberately not in the key: the id of the session is dropped
 * together with the cookie, so a counter keyed by it would reset itself on every new session.
 *
 * The address is the one the connection came from and never the one the request claims in
 * X-Forwarded-For. The kernel has no notion of a trusted proxy, so a claimed address is a value the
 * visitor picks, and a counter keyed by a value its own subject picks counts nothing: a brute-forcer
 * resets it by editing a header. The known price is the other way round - behind a balancer, and
 * behind the NAT of one office, visitors share an address, and the misses of one of them are the
 * misses of all. That price is bounded by the most this counter can do, which is to ask for a
 * captcha: a shared address costs the neighbours a picture to read, never their access.
 *
 * Every failure - no address, a storage error, a lock that was not taken - answers with a demand
 * for the captcha and never with a pass. The single exception is a captcha the core refused to
 * issue a code for: see captchaVerdict().
 */
class PasswordProtectionService
{
	private const ATTEMPTS_TTL = 3600;
	private const CAPTCHA_ATTEMPTS_LIMIT = 3;
	private const LOCK_TIMEOUT = 0;
	private const STORAGE_KEY_PREFIX = 'note.external_access.password_attempts.';
	private const RESET_KEY_PREFIX = 'note.external_access.password_attempts_reset.';

	/**
	 * The request is taken in rather than looked up so that the address the counter is keyed by can
	 * be given to it, the way Disk gives it to the service of its own external links. Without one the
	 * current request answers, which is what every caller of the circuit wants.
	 */
	private ?HttpRequest $request = null;

	public function __construct(?HttpRequest $request = null)
	{
		$this->request = $request;
	}

	/**
	 * Reserves one attempt to name the password. The answer is the verdict for the attempt that is
	 * about to happen: `allowed` means the password may be compared, `captchaRequired` means the
	 * visitor has to solve the captcha first (see checkCaptcha()).
	 */
	public function attempt(int $linkRecordId): PasswordProtectionVerdict
	{
		$key = $this->counterKey($linkRecordId);
		if ($key === null)
		{
			return $this->captchaVerdict();
		}

		$attempts = $this->reserveAttempt($key, $this->readResetMoment($linkRecordId));
		if ($attempts === null || $attempts > self::CAPTCHA_ATTEMPTS_LIMIT)
		{
			return $this->captchaVerdict();
		}

		return PasswordProtectionVerdict::allowed();
	}

	/**
	 * The verdict for a miss: the attempt has already been spent by attempt(), so this only tells
	 * whether the next one needs the captcha.
	 */
	public function verdictAfterMiss(int $linkRecordId): PasswordProtectionVerdict
	{
		$key = $this->counterKey($linkRecordId);
		if ($key === null)
		{
			return $this->captchaVerdict();
		}

		$attempts = $this->readAttempts($key, $this->readResetMoment($linkRecordId));
		if ($attempts === null || $attempts >= self::CAPTCHA_ATTEMPTS_LIMIT)
		{
			return $this->captchaVerdict();
		}

		return PasswordProtectionVerdict::allowed();
	}

	/**
	 * Forgets the attempts of every visitor of this link: the password is gone, and a link without
	 * a password must be indistinguishable from one that never had it - there is nothing left to
	 * guess, so no counter may stand.
	 *
	 * The counters themselves are keyed by address and cannot be enumerated, so the moment of the
	 * drop is written down instead: every counter older than it reads as empty. A moment that
	 * cannot be written leaves the counters standing - the protection fails closed here as it does
	 * everywhere else in this service.
	 *
	 * Only the drop of a password does this. A change of one does not: clearing the counter on a
	 * change would let the very brute-force the counter guards against wipe it by naming a new
	 * password, and after a change there is still a password to guess.
	 */
	public function forgetAttempts(int $linkRecordId): void
	{
		if ($linkRecordId <= 0)
		{
			return;
		}

		try
		{
			$this->getStorage()->set(
				self::RESET_KEY_PREFIX . $linkRecordId,
				['at' => time()],
				self::ATTEMPTS_TTL,
			);
		}
		catch (Throwable)
		{
		}
	}

	/**
	 * The password has been named correctly: the counter of this pair is dropped, so a visitor who
	 * once got in does not carry their misses around.
	 */
	public function onSuccess(int $linkRecordId): void
	{
		$key = $this->counterKey($linkRecordId);
		if ($key === null)
		{
			return;
		}

		$this->runLocked($key, fn (): bool => $this->getStorage()->delete($key));
	}

	/**
	 * @return bool false both when the word is wrong and when the check itself could not be made -
	 *              a captcha that cannot be verified is not a captcha that passed.
	 */
	public function checkCaptcha(?string $captchaCode, ?string $captchaWord): bool
	{
		$captchaCode = trim((string)$captchaCode);
		$captchaWord = trim((string)$captchaWord);
		if ($captchaCode === '' || $captchaWord === '')
		{
			return false;
		}

		try
		{
			return $this->verifyCaptchaCode($captchaCode, $captchaWord);
		}
		catch (Throwable)
		{
			return false;
		}
	}

	/**
	 * A captcha is demanded only together with the code that lets it be solved. When the core hands
	 * out no code, the attempt is let through instead: checkCaptcha() refuses an empty code
	 * unconditionally, so a demand without one would turn even a correct password into a refusal
	 * until the counter expires - a lockout caused by our own failure and with no way out of it.
	 */
	private function captchaVerdict(): PasswordProtectionVerdict
	{
		$code = $this->generateCaptchaCode();

		return $code === null
			? PasswordProtectionVerdict::allowed()
			: PasswordProtectionVerdict::captchaRequired($code)
		;
	}

	/**
	 * @return string|null null when there is no address to key the counter by: no key means no
	 *                     counter, and the caller reads that as a refusal.
	 */
	private function counterKey(int $linkRecordId): ?string
	{
		if ($linkRecordId <= 0)
		{
			return null;
		}

		$ip = trim($this->resolveSourceIp());

		return $ip === '' ? null : self::STORAGE_KEY_PREFIX . $linkRecordId . '.' . hash('sha256', $ip);
	}

	/**
	 * @return int|null the number of attempts already spent, null when the state cannot be read
	 */
	private function readAttempts(string $key, ?int $resetAt): ?int
	{
		try
		{
			return $this->extractAttempts($this->getStorage()->get($key, []), $resetAt);
		}
		catch (Throwable)
		{
			return null;
		}
	}

	/**
	 * @return int|null the moment the attempts of this link were forgotten, null when there is
	 *                  none or when it cannot be read - an unreadable moment forgets nothing
	 */
	private function readResetMoment(int $linkRecordId): ?int
	{
		try
		{
			$payload = $this->getStorage()->get(self::RESET_KEY_PREFIX . $linkRecordId, []);
		}
		catch (Throwable)
		{
			return null;
		}

		$at = is_array($payload) ? (int)($payload['at'] ?? 0) : 0;

		return $at > 0 ? $at : null;
	}

	/**
	 * @return int|null the ordinal of the reserved attempt, null when it could not be reserved -
	 *                  a storage error or a lock that was not taken both mean a refusal
	 */
	private function reserveAttempt(string $key, ?int $resetAt): ?int
	{
		$reserved = $this->runLocked($key, function () use ($key, $resetAt): ?int {
			$stored = $this->getStorage()->get($key, []);
			$attempts = $this->extractAttempts($stored, $resetAt) + 1;
			if (!$this->getStorage()->set($key, ['attempts' => $attempts, 'at' => time()], self::ATTEMPTS_TTL))
			{
				return null;
			}

			return $attempts;
		});

		return is_int($reserved) ? $reserved : null;
	}

	/**
	 * A counter written before the attempts of the link were forgotten counts as empty. Counters
	 * stored before this rule existed carry no moment of their own and are read as older than any
	 * reset, which is the safe reading: they are at most an hour old anyway.
	 */
	private function extractAttempts(mixed $payload, ?int $resetAt): int
	{
		if (!is_array($payload))
		{
			return 0;
		}

		if ($resetAt !== null && (int)($payload['at'] ?? 0) <= $resetAt)
		{
			return 0;
		}

		return max((int)($payload['attempts'] ?? 0), 0);
	}

	private function runLocked(string $key, callable $callback): mixed
	{
		try
		{
			$connection = Application::getInstance()->getConnection();
			if (!$connection->lock($key, self::LOCK_TIMEOUT))
			{
				return null;
			}

			try
			{
				return $callback();
			}
			finally
			{
				$connection->unlock($key);
			}
		}
		catch (Throwable)
		{
			return null;
		}
	}

	// Seams for tests: the storage, the address and the captcha are all process-wide state.

	protected function getStorage(): PersistentStorageInterface
	{
		return ServiceLocator::getInstance()->get(PersistentStorageInterface::class);
	}

	protected function resolveSourceIp(): string
	{
		$request = $this->request ?? Context::getCurrent()?->getRequest();

		return $request instanceof HttpRequest ? (string)$request->getRemoteAddress() : '';
	}

	protected function generateCaptchaCode(): ?string
	{
		global $APPLICATION;

		if (!is_object($APPLICATION) || !method_exists($APPLICATION, 'CaptchaGetCode'))
		{
			return null;
		}

		$code = (string)$APPLICATION->CaptchaGetCode();

		return $code === '' ? null : $code;
	}

	protected function verifyCaptchaCode(string $captchaCode, string $captchaWord): bool
	{
		global $APPLICATION;

		return is_object($APPLICATION)
			&& method_exists($APPLICATION, 'CaptchaCheckCode')
			&& $APPLICATION->CaptchaCheckCode($captchaWord, $captchaCode) === true
		;
	}
}
