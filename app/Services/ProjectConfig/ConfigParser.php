<?php

namespace App\Services\ProjectConfig;

use JsonException;

/**
 * Checks the structure of a coolify.json file. Adapters check the fields of each resource later.
 */
class ConfigParser
{
    public const MAX_BYTES = 1048576;

    public const FORMAT_VERSION = 1;

    public const KEY_PATTERN = '/^[a-z][a-z0-9-]{0,62}\z/';

    public const ENV_NAME_PATTERN = '/^[A-Za-z_][A-Za-z0-9_]*\z/';

    public const TYPES = ['application', 'postgresql', 'mysql', 'mariadb', 'mongodb', 'redis', 'keydb', 'dragonfly', 'clickhouse', 'sqlite', 'service'];

    public const GENERATE_KINDS = ['password', 'base64', 'uuid'];

    public const REF_PATTERN = '/^((team|project|environment|server|vault)\.[A-Za-z_][A-Za-z0-9_]*|resource\.[a-z][a-z0-9-]{0,62}\.(internal_url|host|port))\z/';

    private const TOP_LEVEL_KEYS = ['$schema', 'version', 'minimum_coolify_version', 'project', 'resources', 'environments', 'moved', 'unmanage'];

    private const ENVIRONMENT_KEYS = ['branch', 'resources', 'import'];

    private const ENV_FORMS = ['value', 'required', 'generate', 'ref'];

    private const ENV_KEYS = ['value', 'required', 'generate', 'ref', 'length', 'rotation', 'buildtime', 'runtime', 'literal', 'multiline', 'preview'];

    /** @var list<ConfigError> */
    private array $errors = [];

    public function parse(string $raw, ?string $linkedProjectName = null): ConfigDocument
    {
        $this->errors = [];

        if (strlen($raw) > self::MAX_BYTES) {
            throw ConfigException::at('', 'The file is larger than 1 MiB.');
        }

        $fileHash = hash('sha256', $raw);
        $text = str_starts_with($raw, "\xEF\xBB\xBF") ? substr($raw, 3) : $raw;

        try {
            $data = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw ConfigException::at('', 'Invalid JSON: '.$e->getMessage().'.');
        }

        if (! is_array($data) || ($data !== [] && array_is_list($data))) {
            throw ConfigException::at('', 'The file must contain a JSON object.');
        }

        $data = $this->withoutExtensionKeys($data);

        $this->checkKeys($data, self::TOP_LEVEL_KEYS, '');
        $this->checkVersion($data);
        $this->checkProject($data, $linkedProjectName);

        $resources = $this->checkResources($data['resources'] ?? null, 'resources', isOverride: false, required: true);
        $environments = $this->checkEnvironments($data['environments'] ?? null);
        $moved = $this->checkMoved($data['moved'] ?? [], $resources);
        $unmanage = $this->checkUnmanage($data['unmanage'] ?? [], $resources);

        if ($this->errors !== []) {
            throw new ConfigException($this->errors);
        }

        return new ConfigDocument(
            version: $data['version'],
            project: $data['project'] ?? null,
            resources: $resources,
            environments: $environments,
            moved: $moved,
            unmanage: $unmanage,
            fileHash: $fileHash,
        );
    }

    private function error(string $path, string $message): void
    {
        $this->errors[] = new ConfigError($path, $message);
    }

    private function path(string $base, string|int $key): string
    {
        return $base === '' ? (string) $key : "{$base}.{$key}";
    }

    private function isMap(mixed $value): bool
    {
        return is_array($value) && ($value === [] || ! array_is_list($value));
    }

    /**
     * Removes keys that start with `x-`, at every level.
     */
    private function withoutExtensionKeys(array $data): array
    {
        $result = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && str_starts_with($key, 'x-')) {
                continue;
            }
            $result[$key] = is_array($value) ? $this->withoutExtensionKeys($value) : $value;
        }

        return $result;
    }

    /**
     * @param  list<string>  $allowed
     */
    private function checkKeys(array $data, array $allowed, string $base): void
    {
        foreach (array_keys($data) as $key) {
            if (! in_array($key, $allowed, true)) {
                $this->error($this->path($base, $key), 'Unknown key.');
            }
        }
    }

    private function checkVersion(array $data): void
    {
        if (! array_key_exists('version', $data)) {
            $this->error('version', 'Add "version": 1.');

            return;
        }
        if ($data['version'] !== self::FORMAT_VERSION) {
            $this->error('version', 'This Coolify version reads format version 1 only.');
        }

        $minimum = $data['minimum_coolify_version'] ?? null;
        if ($minimum === null) {
            return;
        }
        if (! is_string($minimum) || ! preg_match('/^\d+\.\d+\.\d+/', $minimum)) {
            $this->error('minimum_coolify_version', 'Use a version like "4.7.0".');

            return;
        }
        $current = (string) config('constants.coolify.version');
        if (version_compare(ltrim($current, 'v'), $minimum, '<')) {
            $this->error('minimum_coolify_version', "This file needs Coolify {$minimum} or newer. This instance runs {$current}.");
        }
    }

    private function checkProject(array $data, ?string $linkedProjectName): void
    {
        if (! array_key_exists('project', $data)) {
            return;
        }
        if (! is_string($data['project']) || $data['project'] === '') {
            $this->error('project', 'Use the project name as text.');

            return;
        }
        if ($linkedProjectName !== null && $data['project'] !== $linkedProjectName) {
            $this->error('project', "This file is for project \"{$data['project']}\", but it is linked to project \"{$linkedProjectName}\".");
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function checkResources(mixed $resources, string $base, bool $isOverride, bool $required): array
    {
        if ($resources === null && ! $required) {
            return [];
        }
        if (! $this->isMap($resources)) {
            $this->error($base, 'Use an object of resources.');

            return [];
        }

        foreach ($resources as $key => $resource) {
            $path = $this->path($base, $key);
            if (! is_string($key) || ! preg_match(self::KEY_PATTERN, $key)) {
                $this->error($path, 'A resource key uses lowercase letters, digits, and hyphens, starts with a letter, and has at most 63 characters.');

                continue;
            }
            if (! $this->isMap($resource)) {
                $this->error($path, 'Use an object.');

                continue;
            }
            $this->checkResource($resource, $path, $isOverride);
        }

        return $resources;
    }

    private function checkResource(array $resource, string $path, bool $isOverride): void
    {
        if (array_key_exists('type', $resource) || ! $isOverride) {
            if (! in_array($resource['type'] ?? null, self::TYPES, true)) {
                $this->error("{$path}.type", 'Use one of: '.implode(', ', self::TYPES).'.');
            }
        }
        if (array_key_exists('enabled', $resource) && (! $isOverride || ! is_bool($resource['enabled']))) {
            $this->error("{$path}.enabled", $isOverride ? 'Use true or false.' : 'Use "enabled" only in an environment override.');
        }

        $env = $resource['env'] ?? null;
        if ($env === null) {
            return;
        }
        if (! $this->isMap($env)) {
            $this->error("{$path}.env", 'Use an object of variables.');

            return;
        }
        foreach ($env as $name => $entry) {
            $this->checkEnvEntry($name, $entry, "{$path}.env.{$name}", allowNull: $isOverride, allowPreview: true);
        }
    }

    private function checkEnvEntry(string|int $name, mixed $entry, string $path, bool $allowNull, bool $allowPreview): void
    {
        if (! is_string($name) || ! preg_match(self::ENV_NAME_PATTERN, $name)) {
            $this->error($path, 'A variable name uses letters, digits, and underscores, and does not start with a digit.');

            return;
        }
        if ($entry === null && $allowNull) {
            return;
        }
        if (! $this->isMap($entry)) {
            $this->error($path, 'Use an object with one of: value, required, generate, ref.');

            return;
        }

        $allowedKeys = $allowPreview ? self::ENV_KEYS : array_values(array_diff(self::ENV_KEYS, ['preview']));
        $this->checkKeys($entry, $allowedKeys, $path);

        $forms = array_values(array_intersect(self::ENV_FORMS, array_keys($entry)));
        if (count($forms) !== 1) {
            $this->error($path, 'Use exactly one of: value, required, generate, ref.');

            return;
        }

        $form = $forms[0];
        $value = $entry[$form];
        match ($form) {
            'value' => is_string($value) ? null : $this->error($path, 'Use text for "value".'),
            'required' => $value === true ? null : $this->error($path, 'Use "required": true.'),
            'generate' => in_array($value, self::GENERATE_KINDS, true) ? null : $this->error("{$path}.generate", 'Use one of: '.implode(', ', self::GENERATE_KINDS).'.'),
            'ref' => is_string($value) && preg_match(self::REF_PATTERN, $value) ? null : $this->error("{$path}.ref", 'Use team.X, project.X, environment.X, server.X, vault.X, or resource.<key>.internal_url|host|port.'),
        };

        if (array_key_exists('length', $entry)) {
            $length = $entry['length'];
            if (! in_array($entry['generate'] ?? null, ['password', 'base64'], true) || ! is_int($length) || $length < 8 || $length > 256) {
                $this->error("{$path}.length", 'Use a length from 8 to 256 with "generate": "password" or "base64".');
            }
        }
        if (array_key_exists('rotation', $entry) && ($form !== 'generate' || ! is_int($entry['rotation']) || $entry['rotation'] < 0)) {
            $this->error("{$path}.rotation", 'Use a whole number of 0 or more with "generate".');
        }
        foreach (['buildtime', 'runtime', 'literal', 'multiline'] as $flag) {
            if (array_key_exists($flag, $entry) && ! is_bool($entry[$flag])) {
                $this->error("{$path}.{$flag}", 'Use true or false.');
            }
        }
        if ($allowPreview && array_key_exists('preview', $entry)) {
            $this->checkEnvEntry($name, $entry['preview'], "{$path}.preview", allowNull: false, allowPreview: false);
        }
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function checkEnvironments(mixed $environments): array
    {
        if (! $this->isMap($environments) || $environments === []) {
            $this->error('environments', 'Add at least one environment, for example "production": {"branch": "main"}.');

            return [];
        }

        $branches = [];
        foreach ($environments as $name => $environment) {
            $path = $this->path('environments', $name);
            if (! is_string($name) || trim($name) === '') {
                $this->error($path, 'Use the environment name as the key.');

                continue;
            }
            if (! $this->isMap($environment)) {
                $this->error($path, 'Use an object.');

                continue;
            }
            $this->checkKeys($environment, self::ENVIRONMENT_KEYS, $path);

            $branch = $environment['branch'] ?? null;
            if (! is_string($branch) || trim($branch) === '') {
                $this->error("{$path}.branch", 'Add the git branch of this environment.');
            } elseif (isset($branches[$branch])) {
                $this->error("{$path}.branch", "Branch \"{$branch}\" already belongs to environment \"{$branches[$branch]}\".");
            } else {
                $branches[$branch] = $name;
            }

            $this->checkResources($environment['resources'] ?? null, "{$path}.resources", isOverride: true, required: false);
            $this->checkImports($environment['import'] ?? [], "{$path}.import");
        }

        return $environments;
    }

    private function checkImports(mixed $imports, string $base): void
    {
        if (! is_array($imports) || ($imports !== [] && ! array_is_list($imports))) {
            $this->error($base, 'Use a list of {"to": "<key>", "uuid": "<uuid>"}.');

            return;
        }
        foreach ($imports as $index => $import) {
            $path = "{$base}.{$index}";
            if (! $this->isMap($import)) {
                $this->error($path, 'Use {"to": "<key>", "uuid": "<uuid>"}.');

                continue;
            }
            $this->checkKeys($import, ['to', 'uuid'], $path);
            if (! is_string($import['to'] ?? null) || ! preg_match(self::KEY_PATTERN, $import['to'])) {
                $this->error("{$path}.to", 'Use a resource key.');
            }
            if (! is_string($import['uuid'] ?? null) || trim($import['uuid']) === '') {
                $this->error("{$path}.uuid", 'Add the UUID of the resource to adopt.');
            }
        }
    }

    /**
     * @return list<array{from: string, to: string}>
     */
    private function checkMoved(mixed $moved, array $resources): array
    {
        if (! is_array($moved) || ($moved !== [] && ! array_is_list($moved))) {
            $this->error('moved', 'Use a list of {"from": "<old key>", "to": "<new key>"}.');

            return [];
        }
        foreach ($moved as $index => $entry) {
            $path = "moved.{$index}";
            if (! $this->isMap($entry)) {
                $this->error($path, 'Use {"from": "<old key>", "to": "<new key>"}.');

                continue;
            }
            $this->checkKeys($entry, ['from', 'to'], $path);
            foreach (['from', 'to'] as $side) {
                if (! is_string($entry[$side] ?? null) || ! preg_match(self::KEY_PATTERN, $entry[$side])) {
                    $this->error("{$path}.{$side}", 'Use a resource key.');
                }
            }
            if (is_string($entry['from'] ?? null) && array_key_exists($entry['from'], $resources)) {
                $this->error("{$path}.from", "\"{$entry['from']}\" is still in resources.");
            }
        }

        return $moved;
    }

    /**
     * @return list<string>
     */
    private function checkUnmanage(mixed $unmanage, array $resources): array
    {
        if (! is_array($unmanage) || ($unmanage !== [] && ! array_is_list($unmanage))) {
            $this->error('unmanage', 'Use a list of resource keys.');

            return [];
        }
        foreach ($unmanage as $index => $key) {
            if (! is_string($key) || ! preg_match(self::KEY_PATTERN, $key)) {
                $this->error("unmanage.{$index}", 'Use a resource key.');
            } elseif (array_key_exists($key, $resources)) {
                $this->error("unmanage.{$index}", "\"{$key}\" is still in resources.");
            }
        }

        return $unmanage;
    }
}
