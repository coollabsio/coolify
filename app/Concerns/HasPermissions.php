<?php

declare(strict_types=1);

namespace App\Concerns;

use App\Enums\Permission;
use App\Enums\UserRole;
use App\Models\Scopes\WorkspaceScope;
use App\Models\User;
use App\Models\WorkspaceMember;

use function is_string;

/**
 * @mixin User
 */
trait HasPermissions
{
    public function hasPermission(Permission ...$permissions): bool
    {
        $member = $this->membership();

        if ($member === null) {
            return false;
        }

        $granted = $member->role === UserRole::Custom
            ? ($member->customRole->permissions ?? collect())
            : collect($member->role->permissions());

        return array_all($permissions, fn (Permission $permission): bool => $granted->containsStrict($permission));
    }

    private function membership(): ?WorkspaceMember
    {
        return once(function (): ?WorkspaceMember {
            $memberId = session('workspace_member') ?? context('workspace_member');

            if (! is_string($memberId) || $memberId === '') {
                return null;
            }

            return $this->memberships()
                ->withoutGlobalScope(WorkspaceScope::class)
                ->find($memberId);
        });
    }
}
