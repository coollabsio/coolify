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
        $ctx = ApplyContext::for(self::linkFor($environment));
        $resources = [];
        $imports = [];

        foreach ($this->registry->all() as $adapter) {
            $models = $adapter->modelClass()::query()
                ->where('environment_id', $environment->id)
                ->where('id', '!=', 0)
                ->orderBy('id')
                ->get();
            foreach ($models as $model) {
                $key = $model->config_key ?? $this->proposeKey((string) $model->name, $resources);
                $entry = $adapter->read($model, $ctx);
                if (($entry['name'] ?? null) === $key) {
                    unset($entry['name']);
                }
                $resources[$key] = $entry;
                if ($model->config_key === null) {
                    $imports[] = ['to' => $key, 'uuid' => $model->uuid];
                }
            }
        }

        $link = $environment->configLink;
        $environmentEntry = ['branch' => $link?->isGit() ? $link->branch : 'main'];
        if ($imports !== []) {
            $environmentEntry['import'] = $imports;
        }

        return [
            '$schema' => 'https://coolify.io/schemas/coolify.v1.json',
            'version' => ConfigParser::FORMAT_VERSION,
            'project' => $environment->project->name,
            'resources' => $resources,
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

    private function proposeKey(string $name, array $taken): string
    {
        $base = Str::of($name)->lower()->replaceMatches('/[^a-z0-9]+/', '-')->trim('-')->substr(0, 60)->value();
        if ($base === '' || ! preg_match('/^[a-z]/', $base)) {
            $base = 'resource'.($base === '' ? '' : '-'.$base);
        }
        $key = $base;
        $suffix = 2;
        while (array_key_exists($key, $taken)) {
            $key = "{$base}-{$suffix}";
            $suffix++;
        }

        return $key;
    }
}
