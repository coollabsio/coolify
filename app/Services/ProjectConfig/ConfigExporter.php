<?php

namespace App\Services\ProjectConfig;

use App\Models\Environment;
use App\Models\EnvironmentConfigLink;
use Illuminate\Support\Str;

/**
 * Writes the current state of an environment as a coolify.json file, without secret values.
 */
class ConfigExporter
{
    public function __construct(private readonly AdapterRegistry $registry) {}

    public function export(Environment $environment): array
    {
        $link = self::linkFor($environment);
        $ctx = ApplyContext::for($link);
        $defaultPlacement = $link->defaultPlacement();
        $resources = [];
        $imports = [];

        $models = collect($this->registry->all())->flatMap(fn ($adapter) => $adapter->modelClass()::query()
            ->where('environment_id', $environment->id)
            ->where('id', '!=', 0)
            ->orderBy('id')
            ->get()
            ->map(fn ($model) => [$adapter, $model]));
        // Managed resources keep their keys, so a proposed key never takes one of them.
        $taken = $models->pluck('1.config_key')->filter()->flip()->all();

        foreach ($models as [$adapter, $model]) {
            $key = $model->config_key;
            if ($key === null) {
                $key = $this->proposeKey((string) $model->name, $taken);
                $taken[$key] = true;
                $imports[] = ['to' => $key, 'uuid' => $model->uuid];
            }
            $entry = $adapter->read($model, $ctx);
            if (($entry['name'] ?? null) === $key) {
                unset($entry['name']);
            }
            // The link places resources that name no server, so the file stays portable.
            if (isset($defaultPlacement['server']) && ($entry['server'] ?? null) === $defaultPlacement['server']
                && ($entry['destination'] ?? null) === ($defaultPlacement['destination'] ?? null)) {
                unset($entry['server'], $entry['destination']);
            }
            $resources[$key] = $entry;
        }

        $environmentEntry = ['branch' => $link->isGit() ? $link->branch : 'main'];
        if ($imports !== []) {
            $environmentEntry['import'] = $imports;
        }

        return [
            '$schema' => 'https://coolify.io/schemas/coolify.v1.json',
            'version' => ConfigParser::FORMAT_VERSION,
            'resources' => $resources === [] ? new \stdClass : $resources,
            'environments' => [$environment->name => $environmentEntry],
        ];
    }

    /**
     * The saved link of the environment, or an unsaved API link for planning.
     */
    public static function linkFor(Environment $environment): EnvironmentConfigLink
    {
        $link = $environment->configLink ?? new EnvironmentConfigLink([
            'environment_id' => $environment->id,
            'mode' => EnvironmentConfigLink::MODE_API,
        ]);
        $link->setRelation('environment', $environment);

        return $link;
    }

    /**
     * A key that matches ConfigParser::KEY_PATTERN (at most 63 characters, also with its prefix and suffix).
     */
    private function proposeKey(string $name, array $taken): string
    {
        $base = Str::of($name)->lower()->replaceMatches('/[^a-z0-9]+/', '-')->trim('-')->value();
        if ($base === '' || ! preg_match('/^[a-z]/', $base)) {
            $base = 'resource'.($base === '' ? '' : '-'.$base);
        }
        $key = self::limitKey($base, '');
        for ($suffix = 2; array_key_exists($key, $taken); $suffix++) {
            $key = self::limitKey($base, "-{$suffix}");
        }

        return $key;
    }

    private static function limitKey(string $base, string $suffix): string
    {
        return rtrim(substr($base, 0, 63 - strlen($suffix)), '-').$suffix;
    }
}
