<?php

namespace App\Models;

use App\Enums\TerminalUploadedFileStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TerminalUploadedFile extends BaseModel
{
    protected $fillable = [
        'user_id',
        'team_id',
        'server_id',
        'upload_token',
        'container_uuid',
        'original_name',
        'stored_filename',
        'mime_type',
        'size_bytes',
        'local_path',
        'server_path',
        'container_path',
        'status',
        'uploaded_at',
        'expires_at',
        'finalized_at',
        'deleted_at',
        'cleanup_attempts',
        'last_cleanup_error',
    ];

    protected function casts(): array
    {
        return [
            'status' => TerminalUploadedFileStatus::class,
            'size_bytes' => 'integer',
            'cleanup_attempts' => 'integer',
            'uploaded_at' => 'datetime',
            'expires_at' => 'datetime',
            'finalized_at' => 'datetime',
            'deleted_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function scopeOwnedByCurrentUserAndTeam(Builder $query): Builder
    {
        return $query
            ->where('user_id', auth()->id())
            ->where('team_id', data_get(auth()->user()?->currentTeam(), 'id'));
    }

    public function scopeVisible(Builder $query): Builder
    {
        return $query
            ->whereNull('deleted_at')
            ->whereNotNull('finalized_at')
            ->whereIn('status', [
                TerminalUploadedFileStatus::Active,
                TerminalUploadedFileStatus::DeleteFailed,
                TerminalUploadedFileStatus::Deleting,
            ]);
    }

    public function scopeExpiredForCleanup(Builder $query): Builder
    {
        return $query
            ->whereNull('deleted_at')
            ->whereNotNull('finalized_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->whereIn('status', [
                TerminalUploadedFileStatus::Active,
                TerminalUploadedFileStatus::DeleteFailed,
            ]);
    }

    public function scopePendingForCleanup(Builder $query, int $hours): Builder
    {
        return $query
            ->whereNull('deleted_at')
            ->whereNull('finalized_at')
            ->where('uploaded_at', '<=', now()->subHours($hours))
            ->whereIn('status', [
                TerminalUploadedFileStatus::Pending,
                TerminalUploadedFileStatus::DeleteFailed,
            ]);
    }
}
