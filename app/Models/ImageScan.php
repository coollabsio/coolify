<?php

namespace App\Models;

use App\Enums\ImageScanErrorCode;
use App\Enums\ImageScanStatus;
use App\Services\ImageScan\ImageRef;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use InvalidArgumentException;

/**
 * Scan result of one image for one resource on one server.
 */
class ImageScan extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'server_id',
        'image_sbom_id',
        'image_id',
        'repo_digest',
        'resource_type',
        'resource_id',
        'image_ref',
        'deployment_queue_id',
        'status',
        'error_code',
        'error_context',
        'summary',
        'findings',
        'candidates',
        'base_image_ref',
        'builds_image',
        'is_baseline',
        'notified_ids',
        'notified_candidates',
        'grype_db_built_at',
        'scanned_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ImageScanStatus::class,
            'error_code' => ImageScanErrorCode::class,
            'error_context' => 'array',
            'summary' => 'array',
            'findings' => 'array',
            'candidates' => 'array',
            'builds_image' => 'boolean',
            'is_baseline' => 'boolean',
            'notified_ids' => 'array',
            'notified_candidates' => 'array',
            'grype_db_built_at' => 'datetime',
            'scanned_at' => 'datetime',
        ];
    }

    /**
     * Entries of `candidates`. Written as `{status, checked_at, entries}`; older rows hold a bare list of entries.
     *
     * @return list<array<string, mixed>>
     */
    public function candidateEntries(): array
    {
        $candidates = $this->candidates;
        if (! is_array($candidates)) {
            return [];
        }

        $entries = array_is_list($candidates) ? $candidates : ($candidates['entries'] ?? []);

        return is_array($entries) ? array_values(array_filter($entries, 'is_array')) : [];
    }

    /**
     * Result of the last candidate check: ok, no_better, cannot_compare, auth_required, or failed. Null before the first check.
     */
    public function candidateStatus(): ?string
    {
        $status = is_array($this->candidates) && ! array_is_list($this->candidates) ? ($this->candidates['status'] ?? null) : null;

        return is_string($status) ? $status : null;
    }

    /**
     * The better candidates Coolify can switch to: newer tags of the same line first, then a newer build of the same
     * tag. A major version is never one of them.
     *
     * @return list<array<string, mixed>>
     */
    public function updateCandidates(): array
    {
        try {
            $current = ImageRef::parse((string) $this->image_ref);
        } catch (InvalidArgumentException) {
            return [];
        }
        if ($current->digest !== null) {
            return [];
        }

        $currentTag = $current->tag ?? 'latest';
        $line = [];
        $sameTag = [];
        foreach ($this->candidateEntries() as $entry) {
            if (($entry['better'] ?? false) !== true || ! is_string($entry['ref'] ?? null)) {
                continue;
            }

            try {
                $ref = ImageRef::parse($entry['ref']);
            } catch (InvalidArgumentException) {
                continue;
            }
            if ($ref->qualifiedName() !== $current->qualifiedName() || $ref->digest !== null || $ref->tag === null) {
                continue;
            }

            $kind = $entry['kind'] ?? null;
            if ($kind === 'line' && $ref->tag !== $currentTag) {
                $line[] = $entry;
            } elseif ($kind === 'same_tag' && $ref->tag === $currentTag) {
                $sameTag[] = $entry;
            }
        }

        return [...$line, ...$sameTag];
    }

    public function resource(): MorphTo
    {
        return $this->morphTo('resource');
    }

    public function server(): BelongsTo
    {
        return $this->belongsTo(Server::class);
    }

    public function sbom(): BelongsTo
    {
        return $this->belongsTo(ImageSbom::class, 'image_sbom_id');
    }

    public function deploymentQueue(): BelongsTo
    {
        return $this->belongsTo(ApplicationDeploymentQueue::class, 'deployment_queue_id');
    }
}
