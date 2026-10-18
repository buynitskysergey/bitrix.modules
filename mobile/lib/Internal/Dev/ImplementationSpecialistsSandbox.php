<?php

declare(strict_types=1);

namespace Bitrix\Mobile\Internal\Dev;

use Bitrix\Mobile\Provider\CommonUserDto;

/**
 * Dev-only override of the implementation specialists payload.
 *
 * The panel at /dev/mobile/implementation-specialists/ stores a per-user config here, and the menu
 * controller returns this mocked section and list instead of the portal data. It lets every UI state
 * of the control (promo, list, pending rows, limits, renamed integrator) be checked on a real device
 * without seeding integrators on the portal. Disabled unless the developer turns it on for their own
 * user, and it only substitutes what the mobile module itself serves.
 */
final class ImplementationSpecialistsSandbox
{
	public const MAX_TOTAL_COUNT = 10;

	private const OPTION_CATEGORY = 'mobile';
	private const OPTION_NAME = 'dev_implementation_specialists_sandbox';

	// Far above any real portal id, so mocked specialists never collide with real users.
	private const FIRST_USER_ID = 900001;

	private const NAMES = [
		['Илья', 'Кузнецов'],
		['Марина', 'Соболева'],
		['Пётр', 'Гаврилов'],
		['Ольга', 'Терентьева'],
		['Артём', 'Лапин'],
		['Дарья', 'Мельник'],
		['Сергей', 'Ким'],
		['Ника', 'Радченко'],
		['Глеб', 'Астахов'],
		['Юлия', 'Панова'],
	];

	private const INTEGRATOR_NAMES = [
		'ООО «Первый интегратор»',
		'ИП Семёнов',
		'Студия «Ветка»',
	];

	private const WORK_POSITION = 'Специалист по внедрению';

	private array $config;

	public function __construct(private readonly int $userId)
	{
		$this->config = self::read($userId);
	}

	/**
	 * @return array<string, bool|int>
	 */
	public static function getDefaultConfig(): array
	{
		return [
			'enabled' => false,
			'hasSection' => true,
			'canView' => true,
			'canInvite' => true,
			'canInviteMore' => true,
			'canInviteNew' => true,
			'isRenamed' => false,
			'totalCount' => 3,
			'pendingCount' => 1,
			'canCancel' => true,
			'withIntegrator' => true,
			'extranet' => false,
		];
	}

	public function isEnabled(): bool
	{
		return (bool)$this->config['enabled'];
	}

	/**
	 * @return array<string, bool|int>
	 */
	public function getConfig(): array
	{
		return $this->config;
	}

	/**
	 * @param array<string, mixed> $config
	 */
	public function save(array $config): void
	{
		// Merge over the stored config: a partial save keeps untouched keys instead of silently
		// resetting them to defaults.
		$this->config = self::normalize($config + $this->config);

		\CUserOptions::SetOption(self::OPTION_CATEGORY, self::OPTION_NAME, $this->config, false, $this->userId);
	}

	public function reset(): void
	{
		$this->config = self::getDefaultConfig();

		\CUserOptions::DeleteOption(self::OPTION_CATEGORY, self::OPTION_NAME, false, $this->userId);
	}

	/**
	 * Mocked menu section. Null means the portal returned no section at all and the control is hidden.
	 *
	 * @return array{canView: bool, canInvite: bool, canInviteMore: bool, canInviteNew: bool, isRenamed: bool, totalCount: int, users: CommonUserDto[]}|null
	 */
	public function getSection(): ?array
	{
		if (!$this->config['hasSection'])
		{
			return null;
		}

		return [
			'canView' => (bool)$this->config['canView'],
			'canInvite' => (bool)$this->config['canInvite'],
			'canInviteMore' => (bool)$this->config['canInviteMore'],
			'canInviteNew' => (bool)$this->config['canInviteNew'],
			'isRenamed' => (bool)$this->config['isRenamed'],
			'totalCount' => $this->getTotalCount(),
			'users' => $this->getUsers(),
		];
	}

	/**
	 * Mocked dataset of the opened list: users plus their companion data.
	 *
	 * @return array{users: CommonUserDto[], extra: array<int, array{integratorName: string, isPending: bool, isExtranetUser: bool, canCancel: bool}>}
	 */
	public function getListData(): array
	{
		$users = $this->getUsers();

		return [
			'users' => $users,
			'extra' => $this->getExtra($users),
		];
	}

	/**
	 * @return CommonUserDto[]
	 */
	private function getUsers(): array
	{
		$users = [];

		for ($index = 0; $index < $this->getTotalCount(); $index++)
		{
			[$name, $lastName] = self::NAMES[$index % count(self::NAMES)];

			$users[] = new CommonUserDto(
				id: self::FIRST_USER_ID + $index,
				name: $name,
				lastName: $lastName,
				fullName: $name . ' ' . $lastName,
				workPosition: self::WORK_POSITION,
				isExtranet: (bool)$this->config['extranet'],
			);
		}

		return $users;
	}

	/**
	 * @param CommonUserDto[] $users
	 * @return array<int, array{integratorName: string, isPending: bool, isExtranetUser: bool, canCancel: bool}>
	 */
	private function getExtra(array $users): array
	{
		$extra = [];

		foreach ($users as $index => $user)
		{
			$isPending = $this->isPendingIndex($index);

			$extra[$user->id] = [
				'integratorName' => $this->config['withIntegrator']
					? self::INTEGRATOR_NAMES[$index % count(self::INTEGRATOR_NAMES)]
					: '',
				'isPending' => $isPending,
				'isExtranetUser' => (bool)$this->config['extranet'],
				'canCancel' => $isPending && (bool)$this->config['canCancel'],
			];
		}

		return $extra;
	}

	/**
	 * Pending specialists are kept at the tail of the list, like a portal that lists accepted
	 * specialists first.
	 */
	private function isPendingIndex(int $index): bool
	{
		return $index >= $this->getTotalCount() - $this->getPendingCount();
	}

	private function getTotalCount(): int
	{
		return (int)$this->config['totalCount'];
	}

	private function getPendingCount(): int
	{
		return min($this->getTotalCount(), (int)$this->config['pendingCount']);
	}

	/**
	 * @return array<string, bool|int>
	 */
	private static function read(int $userId): array
	{
		$stored = \CUserOptions::GetOption(self::OPTION_CATEGORY, self::OPTION_NAME, null, $userId);

		if (!is_array($stored))
		{
			return self::getDefaultConfig();
		}

		return self::normalize($stored);
	}

	/**
	 * @param array<string, mixed> $config
	 * @return array<string, bool|int>
	 */
	private static function normalize(array $config): array
	{
		$normalized = self::getDefaultConfig();

		foreach ($normalized as $key => $default)
		{
			if (!array_key_exists($key, $config))
			{
				continue;
			}

			$normalized[$key] = is_int($default) ? (int)$config[$key] : (bool)$config[$key];
		}

		$normalized['totalCount'] = max(0, min(self::MAX_TOTAL_COUNT, $normalized['totalCount']));
		$normalized['pendingCount'] = max(0, min($normalized['totalCount'], $normalized['pendingCount']));

		return $normalized;
	}
}
