<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A saved version of the proxy compose file of a server. The file can hold secrets, so it is encrypted.
 */
class ProxyConfigurationVersion extends BaseModel
{
    use HasFactory;

    /** Versions kept per server. The applied version is never pruned. */
    public const MAX_VERSIONS = 20;

    protected $fillable = [
        'server_id',
        'configuration',
        'hash',
        'source',
        'user_id',
        'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'configuration' => 'encrypted',
            'applied_at' => 'datetime',
        ];
    }

    public static function hashOf(string $configuration): string
    {
        return md5(base64_encode($configuration));
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
