<?php

namespace App\Models;

use App\Enums\ImageScanErrorCode;
use App\Enums\ImageScanStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

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
            'grype_db_built_at' => 'datetime',
            'scanned_at' => 'datetime',
        ];
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
