<?php

namespace App\Models;

use App\Enums\ImageScanGateMode;
use App\Enums\ImageScanOnError;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Validation\Rule;

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
        'gate_mode',
        'on_scan_error',
    ];

    protected function casts(): array
    {
        return [
            'gate_mode' => ImageScanGateMode::class,
            'on_scan_error' => ImageScanOnError::class,
        ];
    }

    /**
     * Rules for the editable fields of a policy. A null field inherits.
     *
     * @return array<string, list<mixed>>
     */
    public static function validationRules(): array
    {
        return [
            'gate_mode' => ['nullable', Rule::enum(ImageScanGateMode::class)],
            'on_scan_error' => ['nullable', Rule::enum(ImageScanOnError::class)],
        ];
    }

    public function scope(): MorphTo
    {
        return $this->morphTo('scope');
    }
}
