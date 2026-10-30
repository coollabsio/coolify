<?php

namespace App\Models;

use Laravel\Sanctum\PersonalAccessToken as SanctumPersonalAccessToken;

class PersonalAccessToken extends SanctumPersonalAccessToken
{
    protected $fillable = [
        'name',
        'token',
        'abilities',
        'expires_at',
        'api_token_expiration_warning_sent_at',
        'team_id',
        'scopes',
        'paused_at',
    ];

    protected function casts(): array
    {
        return [
            'api_token_expiration_warning_sent_at' => 'datetime',
            'scopes' => 'array',
            'paused_at' => 'datetime',
        ];
    }

    /**
     * A paused token is kept, but it does not authenticate requests until it is resumed.
     */
    public function isPaused(): bool
    {
        return $this->paused_at !== null;
    }
}
