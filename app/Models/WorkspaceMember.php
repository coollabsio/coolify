<?php

declare(strict_types=1);

namespace App\Models;

use App\Enums\UserRole;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property-read string $id
 * @property-read string $workspace_id
 * @property-read string $user_id
 * @property-read UserRole $role
 * @property-read string|null $custom_role_id
 * @property-read CarbonInterface $created_at
 * @property-read CarbonInterface $updated_at
 */
final class WorkspaceMember extends Model
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
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
            'user_id' => 'string',
            'role' => UserRole::class,
            'custom_role_id' => 'string',
            'created_at' => 'datetime',
            'updated_at' => 'datetime',
        ];
    }
}
