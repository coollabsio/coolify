<?php

namespace App\Traits;

use App\Models\Scopes\VisibleThroughAccessGrants;
use App\Services\Access\AccessResolver;

trait ScopedByAccessGrants
{
    public static function bootScopedByAccessGrants(): void
    {
        static::addGlobalScope(new VisibleThroughAccessGrants);
        static::created(fn () => app(AccessResolver::class)->flush());
        static::deleted(fn () => app(AccessResolver::class)->flush());
    }
}
