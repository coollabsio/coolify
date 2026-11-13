<?php

namespace App\Services\ProjectConfig\Children;

use App\Actions\Service\CreateServiceFromCompose;
use App\Models\Application;
use App\Models\EnvironmentVariable;
use App\Models\Service;
use App\Services\ProjectConfig\ApplyContext;
use App\Services\ProjectConfig\Field;
use App\Services\ProjectConfig\FieldMap;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use LogicException;

class EnvironmentVariables implements ChildRecords
{
    public const FLAG_DEFAULTS = ['buildtime' => true, 'runtime' => true, 'literal' => false, 'multiline' => false];

    public const FLAG_COLUMNS = ['buildtime' => 'is_buildtime', 'runtime' => 'is_runtime', 'literal' => 'is_literal', 'multiline' => 'is_multiline'];

    public const GENERATE_DEFAULT_LENGTH = ['password' => 64, 'base64' => 32];

    /**
     * @param  list<string>  $protectedKeys  Variables that Coolify creates for the resource (for example REDIS_PASSWORD).
     */
    public function __construct(private readonly array $protectedKeys = []) {}

    private const SHARED_REF = '/^\{\{\s*((team|project|environment|server|vault)\.[A-Za-z_][A-Za-z0-9_]*)\s*\}\}\z/';

    public function key(): string
    {
        return 'env';
    }

    public function map(): FieldMap
    {
        return new FieldMap('environment_variables', [
            Field::managed('key', 'env'),
            Field::managed('value', 'env'),
            Field::managed('config_meta', 'env'),
            Field::managed('is_preview', 'env.*.preview'),
            Field::managed('is_buildtime', 'env.*.buildtime'),
            Field::managed('is_runtime', 'env.*.runtime'),
            Field::managed('is_literal', 'env.*.literal'),
            Field::managed('is_multiline', 'env.*.multiline'),
            Field::state('is_shown_once', 'A display choice made in Coolify when the value is set there.'),
            Field::state('comment', 'A note shown in Coolify. It does not change the running configuration.'),
            Field::derived('is_required', 'Coolify sets it from Compose required-variable syntax.'),
            Field::derived('is_shared', 'Coolify sets it when the value references a shared variable.'),
            Field::derived('version', 'Coolify sets the format version when it saves the variable.'),
            Field::derived('order', 'Coolify orders variables as the file lists them.'),
            Field::derived('uses_legacy_escaping', 'Coolify sets it for variables saved by old versions.'),
            Field::derived('preserve_compose_null', 'Coolify sets it from the Compose file.'),
            Field::derived('is_production_value_copy', 'Coolify sets it when the preview variable copies the production value.'),
        ], ['environment_variables' => ['resourceable_type', 'resourceable_id']]);
    }

    public function read(Model $owner): ?array
    {
        $rows = EnvironmentVariable::query()
            ->where('resourceable_type', $owner->getMorphClass())
            ->where('resourceable_id', $owner->getKey())
            ->orderBy('order')->orderBy('id')
            ->get();

        $production = [];
        $preview = [];
        foreach ($rows as $row) {
            if ($this->isGenerated($owner, $row->key) && empty($row->config_meta)) {
                continue;
            }
            if ($row->is_preview) {
                $preview[$row->key] = $this->entry($row);
            } else {
                $production[$row->key] = $this->entry($row);
            }
        }

        foreach ($production as $name => $entry) {
            if (isset($preview[$name]) && $preview[$name] !== $entry) {
                $production[$name]['preview'] = $preview[$name];
            }
        }

        return $production === [] ? null : $production;
    }

    public function canonical(mixed $desired): ?array
    {
        if (! is_array($desired) || $desired === []) {
            return null;
        }

        $result = [];
        foreach ($desired as $name => $entry) {
            $canonical = $this->canonicalEntry($entry);
            if (isset($entry['preview'])) {
                $preview = $this->canonicalEntry($entry['preview']);
                if ($preview !== $canonical) {
                    $canonical['preview'] = $preview;
                }
            }
            $result[$name] = $canonical;
        }

        return $result;
    }

    public function schema(): array
    {
        return ['$ref' => '#/$defs/env'];
    }

    public function write(Model $owner, mixed $desired, ApplyContext $ctx): void
    {
        $desired = is_array($desired) ? $desired : [];
        $rows = EnvironmentVariable::query()
            ->where('resourceable_type', $owner->getMorphClass())
            ->where('resourceable_id', $owner->getKey())
            ->get();
        $hasPreview = $owner instanceof Application;

        $order = 0;
        foreach ($desired as $name => $entry) {
            $order++;
            $production = $rows->first(fn ($row) => $row->key === $name && ! $row->is_preview);
            $production = $this->writeRow($owner, $name, $entry, $production, false, $order, $ctx);
            if (! $hasPreview) {
                continue;
            }
            $preview = EnvironmentVariable::query()
                ->where('resourceable_type', $owner->getMorphClass())
                ->where('resourceable_id', $owner->getKey())
                ->where('key', $name)
                ->where('is_preview', true)
                ->first();
            if (isset($entry['preview'])) {
                $this->writeRow($owner, $name, $entry['preview'], $preview, true, $order, $ctx);
            } else {
                $this->copyToPreview($owner, $production, $preview);
            }
        }

        foreach ($rows as $row) {
            if (array_key_exists($row->key, $desired)) {
                continue;
            }
            if (! $this->isGenerated($owner, $row->key)) {
                $row->withoutAuditLogging(fn () => $row->delete());
            } elseif (! empty($row->config_meta)) {
                $row->config_meta = null;
                $row->withoutAuditLogging(fn () => $row->save());
            }
        }
    }

    private function writeRow(Model $owner, string $name, array $entry, ?EnvironmentVariable $row, bool $isPreview, int $order, ApplyContext $ctx): EnvironmentVariable
    {
        $row ??= new EnvironmentVariable([
            'key' => $name,
            'resourceable_type' => $owner->getMorphClass(),
            'resourceable_id' => $owner->getKey(),
            'is_preview' => $isPreview,
        ]);
        $meta = $row->config_meta ?? [];

        if (array_key_exists('value', $entry)) {
            $row->value = $entry['value'];
            $meta = ['form' => 'value'];
        } elseif (isset($entry['required'])) {
            if (! $row->exists) {
                $row->value = '';
            }
            $meta = ['form' => 'required'];
        } elseif (isset($entry['generate'])) {
            $kind = $entry['generate'];
            $length = $entry['length'] ?? (self::GENERATE_DEFAULT_LENGTH[$kind] ?? null);
            $rotation = $entry['rotation'] ?? 0;
            $keep = $row->exists && ($meta['form'] ?? null) === 'generate' && ($meta['generate'] ?? null) === $kind
                && ($meta['length'] ?? null) === $length && $rotation <= ($meta['rotation'] ?? 0) && filled($row->value);
            if (! $keep) {
                $row->value = self::generate($kind, $length);
            }
            $meta = array_filter(['form' => 'generate', 'generate' => $kind, 'length' => $length, 'rotation' => $rotation], fn ($value) => $value !== null);
        } else {
            $ref = $entry['ref'];
            $row->value = str_starts_with($ref, 'resource.')
                ? ($ctx->resolvedRefs[$ref] ?? throw new LogicException("The reference {$ref} has no value."))
                : '{{'.$ref.'}}';
            $meta = ['form' => 'ref', 'ref' => $ref];
        }

        $row->config_meta = $meta;
        foreach (self::FLAG_COLUMNS as $flag => $column) {
            $row->{$column} = $entry[$flag] ?? self::FLAG_DEFAULTS[$flag];
        }
        $row->order = $order;
        $created = ! $row->exists;
        $row->withoutAuditLogging(fn () => $row->save());

        if ($created && ! $isPreview && $owner instanceof Application) {
            EnvironmentVariable::query()
                ->where('resourceable_type', $owner->getMorphClass())
                ->where('resourceable_id', $owner->getKey())
                ->where('key', $name)
                ->where('is_preview', true)
                ->whereKeyNot($row->getKey())
                ->get()
                ->each(fn (EnvironmentVariable $copy) => $copy->withoutAuditLogging(fn () => $copy->delete()));
        }

        return $row;
    }

    private function copyToPreview(Model $owner, EnvironmentVariable $production, ?EnvironmentVariable $preview): void
    {
        $preview ??= new EnvironmentVariable([
            'key' => $production->key,
            'resourceable_type' => $owner->getMorphClass(),
            'resourceable_id' => $owner->getKey(),
            'is_preview' => true,
        ]);
        $preview->value = $production->value;
        $preview->config_meta = $production->config_meta;
        foreach (self::FLAG_COLUMNS as $column) {
            $preview->{$column} = $production->{$column};
        }
        $preview->order = $production->order;
        $preview->withoutAuditLogging(fn () => $preview->save());
        // After the save: the saving hook clears the flag when a preview value changes.
        EnvironmentVariable::query()->whereKey($preview->getKey())->update(['is_production_value_copy' => filled($production->value)]);
    }

    public static function generate(string $kind, ?int $length): string
    {
        return match ($kind) {
            'uuid' => (string) Str::uuid(),
            'base64' => substr(base64_encode(random_bytes((int) ceil($length * 3 / 4) + 3)), 0, $length),
            default => Str::password($length ?? 64, symbols: false),
        };
    }

    /**
     * Compose and service variables named SERVICE_* come from Coolify's magic variables.
     */
    public function isGenerated(Model $owner, string $key): bool
    {
        if (in_array($key, $this->protectedKeys, true)) {
            return true;
        }
        $isCompose = $owner instanceof Service || ($owner instanceof Application && $owner->build_pack === 'dockercompose');
        if (! $isCompose) {
            return false;
        }
        if (str_starts_with($key, 'SERVICE_')) {
            return true;
        }

        return in_array($key, $this->composeKeys($owner), true);
    }

    /**
     * Variables that the Compose file or the template defines. The parser creates them, so the file does not have to list them.
     *
     * @return list<string>
     */
    private function composeKeys(Model $owner): array
    {
        preg_match_all('/\$\{?([A-Za-z_][A-Za-z0-9_]*)/', (string) $owner->docker_compose_raw, $matches);
        $keys = $matches[1];
        if ($owner instanceof Service && filled($owner->service_type)) {
            foreach (app(CreateServiceFromCompose::class)->templateVariables($owner->service_type) as $line) {
                $keys[] = str($line)->before('=')->value();
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @return array<string, mixed>
     */
    private function entry(EnvironmentVariable $row): array
    {
        $meta = $row->config_meta ?? [];
        $entry = match ($meta['form'] ?? null) {
            'value' => ['value' => (string) $row->value],
            'required' => ['required' => true],
            'generate' => ['generate' => $meta['generate'] ?? 'password'] + array_filter([
                'length' => $meta['length'] ?? null,
                'rotation' => $meta['rotation'] ?? null,
            ]),
            'ref' => ['ref' => $meta['ref']],
            default => preg_match(self::SHARED_REF, (string) $row->value, $match) ? ['ref' => $match[1]] : ['required' => true],
        };

        foreach (self::FLAG_COLUMNS as $flag => $column) {
            if ((bool) $row->{$column} !== self::FLAG_DEFAULTS[$flag]) {
                $entry[$flag] = (bool) $row->{$column};
            }
        }

        return $this->canonicalEntry($entry);
    }

    /**
     * @return array<string, mixed>
     */
    private function canonicalEntry(array $entry): array
    {
        unset($entry['preview']);
        foreach (self::FLAG_DEFAULTS as $flag => $default) {
            if (array_key_exists($flag, $entry) && $entry[$flag] === $default) {
                unset($entry[$flag]);
            }
        }
        if (isset($entry['generate'])) {
            if (($entry['rotation'] ?? 0) === 0) {
                unset($entry['rotation']);
            }
            if (isset($entry['length']) && $entry['length'] === (self::GENERATE_DEFAULT_LENGTH[$entry['generate']] ?? null)) {
                unset($entry['length']);
            }
        }
        ksort($entry);

        return $entry;
    }
}
