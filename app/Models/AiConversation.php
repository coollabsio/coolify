<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AiConversation extends BaseModel
{
    use HasFactory;

    public const VISIBILITY_PRIVATE = 'private';

    public const VISIBILITY_TEAM = 'team';

    public const STATUS_IDLE = 'idle';

    public const STATUS_RESPONDING = 'responding';

    protected $fillable = [
        'team_id',
        'created_by_user_id',
        'title',
        'visibility',
        'pinned_at',
        'archived_at',
        'sdk_conversation_id',
        'default_provider',
        'default_model',
        'status',
        'responding_user_id',
        'decision_log',
    ];

    protected $attributes = [
        'visibility' => self::VISIBILITY_PRIVATE,
        'status' => self::STATUS_IDLE,
    ];

    protected function casts(): array
    {
        return [
            'pinned_at' => 'datetime',
            'archived_at' => 'datetime',
            'decision_log' => 'array',
        ];
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public static function ownedByCurrentTeam()
    {
        return self::where('team_id', currentTeam()->id);
    }

    public function claim(User $user): bool
    {
        $claimed = static::where('id', $this->id)
            ->where('status', self::STATUS_IDLE)
            ->update([
                'status' => self::STATUS_RESPONDING,
                'responding_user_id' => $user->id,
            ]);

        return $claimed === 1;
    }

    public function release(): void
    {
        static::where('id', $this->id)->update([
            'status' => self::STATUS_IDLE,
            'responding_user_id' => null,
        ]);
    }
}
