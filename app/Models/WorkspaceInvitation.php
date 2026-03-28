<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\InvitationMethod;
use App\Enums\UserRole;
use App\Models\Scopes\WorkspaceScope;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Attributes\ScopedBy;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read string $id
 * @property-read string $workspace_id
 * @property-read string $email
 * @property-read UserRole $role
 * @property-read string|null $custom_role_id
 * @property-read InvitationMethod $via
 * @property-read CarbonInterface $expires_at
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 */
#[ScopedBy([WorkspaceScope::class])]
final class WorkspaceInvitation extends Model
{
    use HasUlids;

    /**
     * @return BelongsTo<Workspace, $this>
     */
    public function workspace(): BelongsTo
    {
        return $this->belongsTo(Workspace::class);
    }

    /**
     * @return BelongsTo<CustomRole, $this>
     */
    public function customRole(): BelongsTo
    {
        return $this->belongsTo(CustomRole::class);
    }

    protected function casts(): array
    {
        return [
            'id' => 'string',
            'workspace_id' => 'string',
            'email' => 'string',
            'role' => UserRole::class,
            'custom_role_id' => 'string',
            'via' => InvitationMethod::class,
            'expires_at' => 'datetime',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
