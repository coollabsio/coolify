<?php

namespace App\Services\ProjectConfig;

/**
 * Builds the desired state of one environment from a parsed file.
 */
class ConfigResolver
{
    public function forBranch(ConfigDocument $doc, string $branch): ResolvedEnvironment
    {
        foreach ($doc->environments as $name => $environment) {
            if (($environment['branch'] ?? null) === $branch) {
                return $this->resolve($doc, (string) $name);
            }
        }

        throw ConfigException::at('environments', "No environment uses branch \"{$branch}\". Add it to \"environments\".");
    }

    public function forEnvironment(ConfigDocument $doc, string $name): ResolvedEnvironment
    {
        if (! array_key_exists($name, $doc->environments)) {
            throw ConfigException::at('environments', "The file has no environment \"{$name}\".");
        }

        return $this->resolve($doc, $name);
    }

    private function resolve(ConfigDocument $doc, string $name): ResolvedEnvironment
    {
        $environment = $doc->environments[$name];
        $errors = [];
        $resources = $doc->resources;

        foreach ($environment['resources'] ?? [] as $key => $override) {
            $path = "environments.{$name}.resources.{$key}";
            if (! array_key_exists($key, $resources)) {
                if (! isset($override['type'])) {
                    $errors[] = new ConfigError("{$path}.type", 'A resource that only this environment has needs a "type".');

                    continue;
                }
                $resources[$key] = $override;

                continue;
            }
            if (isset($override['type']) && $override['type'] !== $resources[$key]['type']) {
                $errors[] = new ConfigError("{$path}.type", 'Change the type in "resources", not in an environment override.');

                continue;
            }
            $resources[$key] = $this->mergeResource($resources[$key], $override);
        }

        foreach ($resources as $key => $resource) {
            if (($resource['enabled'] ?? true) === false) {
                unset($resources[$key]);

                continue;
            }
            unset($resources[$key]['enabled']);
        }

        $imports = $environment['import'] ?? [];
        foreach ($imports as $index => $import) {
            if (! array_key_exists($import['to'], $resources)) {
                $errors[] = new ConfigError("environments.{$name}.import.{$index}.to", "\"{$import['to']}\" is not a resource of this environment.");
            }
        }

        [$ordered, $orderErrors] = $this->order($resources);
        $errors = [...$errors, ...$orderErrors];

        if ($errors !== []) {
            throw new ConfigException($errors);
        }

        return new ResolvedEnvironment(
            name: $name,
            branch: $environment['branch'],
            resources: $ordered,
            moved: $doc->moved,
            unmanage: $doc->unmanage,
            imports: array_values($imports),
            formatVersion: $doc->version,
            fileHash: $doc->fileHash,
        );
    }

    /**
     * Objects merge by key, lists replace, null removes. A variable entry replaces the inherited one.
     */
    private function mergeResource(array $base, array $override): array
    {
        $env = $base['env'] ?? [];
        if (array_key_exists('env', $override)) {
            foreach ($override['env'] ?? [] as $name => $entry) {
                if ($entry === null) {
                    unset($env[$name]);
                } else {
                    $env[$name] = $entry;
                }
            }
            if ($override['env'] === null) {
                $env = [];
            }
            unset($override['env']);
        }

        $merged = $this->merge($base, $override);
        unset($merged['env']);
        if ($env !== []) {
            $merged['env'] = $env;
        }

        return $merged;
    }

    private function merge(array $base, array $override): array
    {
        foreach ($override as $key => $value) {
            if ($value === null) {
                unset($base[$key]);
            } elseif ($this->isMap($value) && isset($base[$key]) && $this->isMap($base[$key])) {
                $base[$key] = $this->merge($base[$key], $value);
            } else {
                $base[$key] = $value;
            }
        }

        return $base;
    }

    private function isMap(mixed $value): bool
    {
        return is_array($value) && $value !== [] && ! array_is_list($value);
    }

    /**
     * Sorts resources so that each one comes after the resources its variables reference.
     *
     * @return array{0: array<string, array<string, mixed>>, 1: list<ConfigError>}
     */
    private function order(array $resources): array
    {
        $errors = [];
        $dependencies = [];
        foreach ($resources as $key => $resource) {
            $dependencies[$key] = [];
            foreach ($this->references($resource) as $name => $target) {
                if (! array_key_exists($target, $resources)) {
                    $errors[] = new ConfigError("resources.{$key}.env.{$name}", "\"{$target}\" is not a resource of this environment.");

                    continue;
                }
                $dependencies[$key][$target] = $name;
            }
        }

        $ordered = [];
        $remaining = $dependencies;
        while ($remaining !== []) {
            $ready = null;
            foreach ($remaining as $key => $needs) {
                if (array_diff_key($needs, $ordered) === []) {
                    $ready = $key;
                    break;
                }
            }
            if ($ready === null) {
                foreach ($remaining as $key => $needs) {
                    foreach ($needs as $target => $name) {
                        if (! isset($ordered[$target])) {
                            $errors[] = new ConfigError("resources.{$key}.env.{$name}", 'This reference is part of a cycle.');
                        }
                    }
                }
                break;
            }
            $ordered[$ready] = $resources[$ready];
            unset($remaining[$ready]);
        }

        return [$ordered, $errors];
    }

    /**
     * @return array<string, string> variable name => referenced resource key
     */
    private function references(array $resource): array
    {
        $references = [];
        foreach ($resource['env'] ?? [] as $name => $entry) {
            foreach ([$entry, $entry['preview'] ?? null] as $candidate) {
                $ref = $candidate['ref'] ?? null;
                if (is_string($ref) && str_starts_with($ref, 'resource.')) {
                    $references[$name] = explode('.', $ref)[1];
                }
            }
        }

        return $references;
    }
}
