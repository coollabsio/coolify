<?php

namespace App\Models\V5;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ApplicationDomain extends V5Model
{
    protected $table = 'v5_application_domains';

    protected bool $hasUuidColumn = false;

    protected $fillable = [
        'application_id',
        'domain',
    ];

    public function application(): BelongsTo
    {
        return $this->belongsTo(Application::class);
    }
}
