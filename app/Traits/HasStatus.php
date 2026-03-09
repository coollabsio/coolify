<?php

namespace App\Traits;

use App\Enums\ApplicationDeploymentStatus;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Str;

use function collect;
use function str;

trait HasStatus
{
    public function status(): Attribute
    {
        return Attribute::make(
            get: function ($value) {
                if ($this->additional_servers->count() > 0) {
                    $statuses = collect([]);
                    foreach ($this->additional_servers as $server) {
                        $statuses->push(str($server->pivot->status)->before(':')->trim()->value());
                    }
                    $statuses->push(str($value)->before(':')->trim()->value());
                    $statuses = $statuses->unique();
                    if ($statuses->count() > 1) {
                        return 'degraded';
                    } else {
                        return $statuses->first();
                    }
                }

                return $value;
            }
        );
    }

    public function realStatus(): Attribute
    {
        return Attribute::make(
            get: function () {
                $value = $this->getRawOriginal('status');
                if ($this->additional_servers->count() > 0) {
                    $statuses = collect([]);
                    foreach ($this->additional_servers as $server) {
                        $statuses->push(str($server->pivot->status)->before(':')->trim()->value());
                    }
                    $statuses->push(str($value)->before(':')->trim()->value());
                    $statuses = $statuses->unique();
                    if ($statuses->count() > 1) {
                        return 'degraded';
                    } else {
                        return $statuses->first();
                    }
                }

                return $value;
            }
        );
    }

    public function health(): Attribute
    {
        return Attribute::make(
            get: function () {
                $value = $this->getRawOriginal('status');
                return str($value)->after(':')->trim()->value();
            }
        );
    }

    public function isHealthy(): Attribute
    {
        return Attribute::make(
            get: function () {
                $value = $this->getRawOriginal('status');
                return str($value)->contains('healthy');
            }
        );
    }
}
