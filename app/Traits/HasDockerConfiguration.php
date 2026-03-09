<?php

namespace App\Traits;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Support\Str;

use function collect;

trait HasDockerConfiguration
{
    public function customNetworkAliases(): Attribute
    {
        return Attribute::make(
            set: function ($value) {
                if (is_null($value) || $value === '') {
                    return null;
                }

                if (is_string($value) && $this->isJson($value)) {
                    $value = json_decode($value, true);
                }

                if (is_string($value) && !is_array($value)) {
                    $value = explode(',', $value);
                }

                $value = collect($value)
                    ->map(function ($alias) {
                        if (is_string($alias)) {
                            return str_replace(' ', '-', trim($alias));
                        }
                        return null;
                    })
                    ->filter()
                    ->unique()
                    ->values()
                    ->toArray();

                return empty($value) ? null : json_encode($value);
            },
            get: function ($value) {
                if (is_null($value)) {
                    return null;
                }

                if (is_string($value) && $this->isJson($value)) {
                    $decoded = json_decode($value, true);
                    return is_array($decoded) ? implode(',', $decoded) : $value;
                }

                return $value;
            }
        );
    }

    public function customNetworkAliasesArray(): Attribute
    {
        return Attribute::make(
            get: function () {
                $value = $this->getRawOriginal('custom_network_aliases');
                if (is_null($value)) {
                    return null;
                }

                if (is_string($value) && $this->isJson($value)) {
                    return json_decode($value, true);
                }

                return is_array($value) ? $value : [];
            }
        );
    }

    public function dockerfileLocation(): Attribute
    {
        return Attribute::make(
            set: function ($value) {
                if (is_null($value) || $value === '') {
                    return '/Dockerfile';
                } else {
                    if ($value !== '/') {
                        return Str::start(Str::replaceEnd('/', '', $value), '/');
                    }
                    return Str::start($value, '/');
                }
            }
        );
    }

    public function dockerComposeLocation(): Attribute
    {
        return Attribute::make(
            set: function ($value) {
                if (is_null($value) || $value === '') {
                    return '/docker-compose.yaml';
                } else {
                    if ($value !== '/') {
                        return Str::start(Str::replaceEnd('/', '', $value), '/');
                    }
                    return Str::start($value, '/');
                }
            }
        );
    }

    private function isJson($string)
    {
        if (!is_string($string)) {
            return false;
        }
        json_decode($string);
        return json_last_error() === JSON_ERROR_NONE;
    }
}
