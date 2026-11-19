<?php

namespace App\Models;

use App\Enums\ImageScanErrorCode;
use App\Services\ImageScan\Finding;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The last scan of the OS packages of a server, one row per server. A failed scan sets `error_code` and keeps the
 * findings of the last completed scan (`scanned_at`). `error_context` holds the values of the error message.
 *
 * @property ?array{critical: int, high: int, medium: int, low: int, unknown: int, fixable: int, exploited: int, likely: int, other_kernels?: int, total: int, running_kernel?: string} $summary
 */
class ServerHostScan extends Model
{
    use HasFactory;

    /** Relevant findings that are stored; the summary counts all of them. */
    public const MAX_STORED_FINDINGS = 500;

    protected $fillable = [
        'server_id',
        'error_code',
        'error_context',
        'eol',
        'summary',
        'findings',
        'findings_truncated',
        'grype_db_built_at',
        'scanned_at',
    ];

    protected function casts(): array
    {
        return [
            'error_code' => ImageScanErrorCode::class,
            'error_context' => 'array',
            'eol' => 'array',
            'summary' => 'array',
            'findings' => 'array',
            'findings_truncated' => 'boolean',
            'grype_db_built_at' => 'datetime',
            'scanned_at' => 'datetime',
        ];
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    /**
     * @return list<Finding>
     */
    public function findingList(): array
    {
        return array_map(fn (array $finding) => Finding::fromArray($finding), $this->findings ?? []);
    }
}
