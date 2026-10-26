<?php

namespace App\Models;

use App\Enums\ImageScanAlertLevel;
use App\Enums\ImageScanOnError;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * Scan rules at one level (instance, team, server or application). A null field inherits.
 * The instance row has `scope_type = null` and concrete values.
 */
class ImageScanPolicy extends BaseModel
{
    use HasFactory;

    protected $fillable = [
        'scope_type',
        'scope_id',
        'alert_level',
        'gate_enabled',
        'on_scan_error',
    ];

    protected function casts(): array
    {
        return [
            'alert_level' => ImageScanAlertLevel::class,
            'gate_enabled' => 'boolean',
            'on_scan_error' => ImageScanOnError::class,
        ];
    }

    public function scope(): MorphTo
    {
        return $this->morphTo('scope');
    }
}
