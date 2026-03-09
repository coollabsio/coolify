<?php
// Auto-generated stubs to satisfy IDE when vendor is missing.
// This file can safely be deleted once `composer install` is run in the project root.

namespace Lorisleiva\Actions\Concerns {
    trait AsAction
    {
        public static function run(...$args): mixed
        {
            return null;
        }
        public static function dispatch(...$args)
        {
        }
    }
}

namespace Illuminate\Contracts\Queue {
    interface ShouldBeEncrypted
    {
    }
    interface ShouldQueue
    {
    }
}

namespace Illuminate\Foundation\Bus {
    trait Dispatchable
    {
        public static function dispatch(...$args)
        {
        }
    }
}

namespace Illuminate\Queue {
    trait InteractsWithQueue
    {
    }
    trait SerializesModels
    {
    }
}

namespace Illuminate\Bus {
    trait Queueable
    {
    }
}

namespace Illuminate\Queue\Middleware {
    class WithoutOverlapping
    {
        public function __construct($key)
        {
        }
        public function expireAfter($minutes)
        {
            return $this;
        }
        public function dontRelease()
        {
            return $this;
        }
    }
}

namespace Illuminate\Database\Eloquent {
    class Builder
    {
        public function where(...$args)
        {
            return $this;
        }
        public function whereIn(...$args)
        {
            return $this;
        }
        public function create(array $attributes = []): mixed
        {
            return null;
        }
        public function update(array $values)
        {
        }
        public function get(...$args)
        {
            return new Collection;
        }
        public function first(...$args): mixed
        {
            return null;
        }
        public function firstOrFail(...$args): mixed
        {
            return null;
        }
        public function orderBy(...$args)
        {
            return $this;
        }
        public function latest(...$args)
        {
            return $this;
        }
    }
    class Model
    {
        public static function create(array $attributes = []): mixed
        {
            return new static;
        }
        public function update(array $attributes = [], array $options = [])
        {
        }
        public function replicate(?array $except = null)
        {
        }
        public static function query()
        {
        }
        public function increment($column, $amount = 1, array $extra = [])
        {
        }
        public function refresh()
        {
        }
        public function saveQuietly(array $options = [])
        {
        }
        public function save(array $options = [])
        {
        }
        public static function saving($callback)
        {
        }
        public static function saved($callback)
        {
        }
        public static function created($callback)
        {
        }
        public static function updated($callback)
        {
        }
        public static function retrieved($callback)
        {
        }
        public static function forceDeleting($callback)
        {
        }
        public static function creating($callback)
        {
        }
        public function hasOne($related)
        {
        }
        public function hasMany($related)
        {
        }
        public function belongsTo($related)
        {
        }
        public function morphTo()
        {
        }
        public function morphMany($related, $name)
        {
        }
        public function morphToMany($related, $name)
        {
        }
        public static function find($id)
        {
        }
        public static function where(...$args)
        {
            return new Builder;
        }
    }
    class Collection
    {
        public function where(...$args)
        {
            return $this;
        }
        public function concat(...$args)
        {
            return $this;
        }
        public function first(...$args): mixed
        {
            return null;
        }
        public function count(...$args): int
        {
            return 0;
        }
        public function values(...$args)
        {
            return $this;
        }
        public function sortByDesc(...$args)
        {
            return $this;
        }
        public function skip(...$args)
        {
            return $this;
        }
        public function toArray(...$args): array
        {
            return [];
        }
    }
    trait SoftDeletes
    {
    }
}

namespace Illuminate\Database\Eloquent\Factories {
    trait HasFactory
    {
    }
}

namespace Illuminate\Database\Eloquent\Relations {
    class HasMany
    {
        public function get(...$args)
        {
            return new \Illuminate\Database\Eloquent\Collection;
        }
    }
    class BelongsTo
    {
    }
    class MorphTo
    {
    }
    class MorphMany
    {
    }
    class MorphToMany
    {
    }
}

namespace Illuminate\Database\Eloquent\Casts {
    class Attribute
    {
        public static function make(...$args): static
        {
            return new static;
        }
    }
}

namespace Illuminate\Support {
    class Str
    {
        public static function start(...$args): static
        {
            return new static;
        }
        public static function replaceEnd(...$args): static
        {
            return new static;
        }
        public static function isEmpty(...$args): bool
        {
            return false;
        }
        public static function slug(...$args): string
        {
            return '';
        }
        public static function replace(...$args): string
        {
            return '';
        }
        public static function contains(...$args): bool
        {
            return false;
        }
        public static function before(...$args): string
        {
            return '';
        }
        public static function after(...$args): string
        {
            return '';
        }
    }
    class Stringable
    {
        public function replaceEnd(...$args): static
        {
            return new static;
        }
        public function start(...$args): static
        {
            return new static;
        }
        public function isEmpty(...$args): bool
        {
            return false;
        }
        public function contains(...$args): bool
        {
            return false;
        }
        public function before(...$args): static
        {
            return new static;
        }
        public function after(...$args): static
        {
            return new static;
        }
        public function slug(...$args): static
        {
            return new static;
        }
        public function replace(...$args): static
        {
            return new static;
        }
        public function trim(...$args): static
        {
            return new static;
        }
        public function value(...$args): mixed
        {
            return null;
        }
        public function __toString(): string
        {
            return '';
        }
        public function toString(): string
        {
            return '';
        }
    }
    class Collection
    {
        public function map(...$args)
        {
            return $this;
        }
        public function filter(...$args)
        {
            return $this;
        }
        public function first(...$args): mixed
        {
            return null;
        }
        public function push(...$args)
        {
            return $this;
        }
        public function unique(...$args)
        {
            return $this;
        }
        public function isEmpty(...$args): bool
        {
            return false;
        }
        public function concat(...$args)
        {
            return $this;
        }
        public function count(...$args): int
        {
            return 0;
        }
        public function values(...$args)
        {
            return $this;
        }
        public function sortByDesc(...$args)
        {
            return $this;
        }
        public function skip(...$args)
        {
            return $this;
        }
        public function toArray(...$args): array
        {
            return [];
        }
    }
    class Carbon
    {
    }
}

namespace Illuminate\Support\Facades {
    class Storage
    {
    }
    class DB
    {
    }
    class Log
    {
    }
}

namespace Carbon {
    class Carbon
    {
        public static function now($tz = null): static
        {
            return new static;
        }
        public function toImmutable(): static
        {
            return new static;
        }
    }
}

namespace Livewire {
    class Component
    {
        public function validate(...$args)
        {
            return [];
        }
    }
}

namespace Visus\Cuid2 {
    class Cuid2
    {
        public function __toString()
        {
            return '';
        }
        public function __construct(...$args)
        {
        }
    }
}

namespace Spatie\SchemalessAttributes {
    trait SchemalessAttributesTrait
    {
    }
}

namespace Spatie\Url {
    class Url
    {
        public static function fromString($url)
        {
            return new static;
        }
        public function withPort($port)
        {
            return $this;
        }
        public function withHost($host)
        {
            return $this;
        }
        public function __toString()
        {
            return '';
        }
    }
}

namespace Spatie\Activitylog\Models {
    class Activity extends \Illuminate\Database\Eloquent\Model
    {
    }
}

namespace Symfony\Component\Yaml {
    class Yaml
    {
        public static function parse($input, $flags = 0): mixed
        {
            return [];
        }
    }
}

namespace OpenApi\Attributes {
    class Schema
    {
    }
}

namespace {
    if (!function_exists('config')) {
        function config(...$args): mixed
        {
            return null;
        }
    }
    if (!function_exists('collect')) {
        function collect(...$args): \Illuminate\Support\Collection
        {
            return new \Illuminate\Support\Collection;
        }
    }
    if (!function_exists('event')) {
        function event(...$args)
        {
        }
    }
    if (!function_exists('str')) {
        function str(...$args): \Illuminate\Support\Stringable
        {
            return new \Illuminate\Support\Stringable;
        }
    }
    if (!function_exists('blank')) {
        function blank(...$args)
        {
        }
    }
    if (!function_exists('filled')) {
        function filled(...$args)
        {
        }
    }
    if (!function_exists('now')) {
        function now(...$args)
        {
        }
    }
    if (!function_exists('data_get')) {
        function data_get(...$args): mixed
        {
            return null;
        }
    }
    if (!function_exists('data_set')) {
        function data_set(...$args)
        {
        }
    }
    if (!function_exists('route')) {
        function route(...$args)
        {
        }
    }
    if (!function_exists('ray')) {
        function ray(...$args)
        {
        }
    }
    if (!function_exists('app')) {
        function app(...$args)
        {
        }
    }
    if (!function_exists('once')) {
        function once(...$args)
        {
        }
    }
    if (!function_exists('dispatch')) {
        function dispatch(...$args)
        {
        }
    }
    if (!function_exists('view')) {
        function view(...$args)
        {
        }
    }
    if (!function_exists('redirect')) {
        function redirect(...$args)
        {
        }
    }
    if (!function_exists('currentTeam')) {
        function currentTeam(...$args)
        {
        }
    }
}

namespace {
    class Log
    {
        public static function __callStatic($method, $args)
        {
        }
    }
}

namespace App\Models {
    class Server extends BaseModel
    {
        public int $team_id;
        public \App\Models\Team|null $team;
        public \App\Models\PrivateKey|null $privateKey;
        public \App\Models\ServerSetting $settings;
    }
    class Service extends BaseModel
    {
        public \Illuminate\Database\Eloquent\Collection $tags;
        public \Illuminate\Database\Eloquent\Collection $applications;
        public \Illuminate\Database\Eloquent\Collection $databases;
    }
    class Team extends BaseModel
    {
        public function notify(...$args)
        {
        }
    }
}
