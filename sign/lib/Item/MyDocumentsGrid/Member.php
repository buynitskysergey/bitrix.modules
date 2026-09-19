<?php

namespace Bitrix\Sign\Item\MyDocumentsGrid;

use Bitrix\Sign\Contract;
use Bitrix\Sign\Type\Member\Role;
use Bitrix\Sign\Type\MemberStatus;

class Member implements Contract\Item
{
	public function __construct(
		public bool $isCurrentUser,
		public ?bool $isStopped,
		public int $userId,
		public ?string $fullName = null,
		public ?string $icon = null,
		public ?int $id = null,
		public ?string $role = null,
		public ?string $status = null,
	)
	{}

	public function isDone(): bool
	{
		return $this->status === MemberStatus::DONE;
	}

	public function isRefused(): bool
	{
		return $this->status === MemberStatus::REFUSED;
	}

	public function isStopped(): bool
	{
		return $this->status === MemberStatus::STOPPED;
	}

	public function isStoppableReady(): bool
	{
		return $this->status === MemberStatus::STOPPABLE_READY;
	}

	public function isSigner(): bool
	{
		return $this->role === Role::SIGNER;
	}

	// Signer and assignee are the roles whose completion is presented as
	// "signed" in the action cell, unlike reviewer/editor which read as
	// "approved"/"edited".
	public function isSigningRole(): bool
	{
		return $this->role === Role::SIGNER || $this->role === Role::ASSIGNEE;
	}
}