<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InfisicalBinding extends BaseModel
{
    use HasFactory;

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    protected $fillable = [
        'infisical_connection_id',
        'environment_id',
        'infisical_project_id',
        'infisical_environment_slug',
        'secret_path',
        'is_enabled',
        'last_synced_at',
        'last_sync_status',
        'last_sync_error',
    ];

    protected $attributes = [
        'secret_path' => '/',
        'is_enabled' => true,
    ];

    protected function casts(): array
    {
        return [
            'is_enabled' => 'boolean',
            'last_synced_at' => 'datetime',
        ];
    }

    public function connection(): BelongsTo
    {
        return $this->belongsTo(InfisicalConnection::class, 'infisical_connection_id');
    }

    public function environment(): BelongsTo
    {
        return $this->belongsTo(Environment::class);
    }

    public function sharedVariables(): HasMany
    {
        return $this->hasMany(SharedEnvironmentVariable::class, 'infisical_binding_id');
    }
}
