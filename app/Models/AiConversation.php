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
        'sdk_conversation_id',
        'default_provider',
        'default_model',
        'status',
        'responding_user_id',
    ];

    protected $attributes = [
        'visibility' => self::VISIBILITY_PRIVATE,
        'status' => self::STATUS_IDLE,
    ];

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
}
