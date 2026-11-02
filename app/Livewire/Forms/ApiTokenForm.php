<?php

namespace App\Livewire\Forms;

use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Form;

/**
 * The fields of the API token form. The page has one for a new token and one for the token in the edit modal.
 */
class ApiTokenForm extends Form
{
    /**
     * Expiry choice in the edit form that keeps the current expiry date.
     */
    public const KEEP_EXPIRY = 'keep';

    public ?string $description = null;

    /**
     * Untyped on purpose: Livewire skips hydrating null into typed properties, which
     * would silently revert a "Never" (null) choice back to the default on the next request.
     *
     * @var int|string|null
     */
    public $expiresInDays = 30;

    public array $permissions = ['read'];

    /**
     * 'team' = every project and resource of the team (scopes null), 'selected' = only $selectedScopes.
     */
    public string $scopeMode = 'team';

    /**
     * Picked scopes as "project:<uuid>", "environment:<uuid>", or "resource:<uuid>".
     *
     * @var list<string>
     */
    public array $selectedScopes = [];

    /**
     * The edit form values of a token. The browser fills the edit modal with them, so opening it needs no request.
     *
     * @return array{id: int, description: string, permissions: list<string>, scopeMode: string, selectedScopes: list<string>, expiresInDays: string, keepLabel: string}
     */
    public static function valuesOf(PersonalAccessToken $token): array
    {
        $permissions = $token->abilities ?? ['read'];
        sort($permissions);
        $currentExpiry = $token->expires_at?->format('Y-m-d') ?? 'Never';

        return [
            'id' => $token->id,
            'description' => $token->name,
            'permissions' => $permissions,
            'scopeMode' => $token->scopes === null ? 'team' : 'selected',
            'selectedScopes' => collect($token->scopes ?? [])->map(fn (array $scope) => "{$scope['scope']}:{$scope['uuid']}")->values()->all(),
            'expiresInDays' => self::KEEP_EXPIRY,
            'keepLabel' => 'Keep current ('.($token->expires_at?->isPast() ? 'expired '.$currentExpiry : $currentExpiry).')',
        ];
    }

    public function updatedPermissions($permissionToUpdate)
    {
        // Re-evaluate policies fresh — never trust stored snapshot booleans.
        if ($permissionToUpdate == 'root' && ! auth()->user()->can('useRootPermissions', PersonalAccessToken::class)) {
            $this->getComponent()->dispatch('error', 'You do not have permission to use root permissions.');
            $this->permissions = array_diff($this->permissions, ['root']);

            return;
        }

        if (in_array($permissionToUpdate, ['write', 'write:sensitive'], true) && ! auth()->user()->can('useWritePermissions', PersonalAccessToken::class)) {
            $this->getComponent()->dispatch('error', 'You do not have permission to use write permissions.');
            $this->permissions = array_diff($this->permissions, ['write', 'write:sensitive']);

            return;
        }

        if ($permissionToUpdate == 'deploy' && ! auth()->user()->can('useDeployPermissions', PersonalAccessToken::class)) {
            $this->getComponent()->dispatch('error', 'You do not have permission to use deploy permissions.');
            $this->permissions = array_diff($this->permissions, ['deploy']);

            return;
        }

        if ($permissionToUpdate == 'read:sensitive' && ! auth()->user()->can('useSensitivePermissions', PersonalAccessToken::class)) {
            $this->getComponent()->dispatch('error', 'You do not have permission to use read:sensitive permissions.');
            $this->permissions = array_diff($this->permissions, ['read:sensitive']);

            return;
        }

        if ($permissionToUpdate == 'delete' && ! auth()->user()->can('useDeletePermissions', PersonalAccessToken::class)) {
            $this->getComponent()->dispatch('error', 'You do not have permission to use delete permissions.');
            $this->permissions = array_diff($this->permissions, ['delete']);

            return;
        }

        if ($permissionToUpdate == 'root' && in_array('root', $this->permissions, true)) {
            $this->permissions = ['root'];
        } elseif ($permissionToUpdate == 'read:sensitive' && ! in_array('read', $this->permissions, true)) {
            $this->permissions[] = 'read';
        } else {
            if (count($this->permissions) == 0) {
                $this->permissions = ['read'];
            }
        }
        sort($this->permissions);
    }
}
