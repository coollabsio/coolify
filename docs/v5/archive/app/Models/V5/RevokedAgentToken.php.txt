<?php

namespace App\Models\V5;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A host-agent JWT that has been revoked (typically on server destroy/re-home).
 *
 * flux does not yet consult this list (see AgentTokenIssuer::revoke docblock);
 * it exists so the Laravel side owns the data and API needed for revocation the
 * moment flux gains a revocation check.
 */
class RevokedAgentToken extends V5Model
{
    protected bool $hasUuidColumn = false;

    protected $table = 'v5_revoked_agent_tokens';

    protected $fillable = [
        'jti',
        'server_id',
        'revoked_at',
        'expires_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'revoked_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Server, $this>
     */
    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }
}
