<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * What the image security notifications told the team about one resource (a scan owner), see `ScanNotifications`.
 * A baseline row records findings and fixes without notifying until it ends.
 */
class ImageScanNotificationState extends Model
{
    use HasFactory;

    protected $fillable = [
        'resource_type',
        'resource_id',
        'team_id',
        'notified_ids',
        'notified_candidates',
        'digest_ids',
        'check_reported',
        'is_baseline',
        'baseline_deployment_queue_id',
    ];

    protected function casts(): array
    {
        return [
            'notified_ids' => 'array',
            'notified_candidates' => 'array',
            'digest_ids' => 'array',
            'check_reported' => 'boolean',
            'is_baseline' => 'boolean',
            'baseline_deployment_queue_id' => 'integer',
        ];
    }

    public function resource(): MorphTo
    {
        return $this->morphTo('resource');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }
}
