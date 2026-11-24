<?php

namespace App\Services\ProjectConfig\Children;

use App\Models\SecretManagerLink;
use App\Services\ProjectConfig\ApplyContext;
use App\Services\ProjectConfig\ConfigError;
use App\Services\ProjectConfig\Field;
use App\Services\ProjectConfig\FieldMap;
use App\Services\ProjectConfig\NamedCredentials;
use App\Support\RemoteSecretReferences;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * The external secret manager sources of a resource: a team integration token by name, plus its settings.
 * The object form `{token, settings}` is the `default` source ({{vault.KEY}}). The list form
 * `[{name, token, settings}, ...]` names several sources ({{vault.<name>.KEY}}).
 */
class SecretManager implements ChildRecords
{
    public function key(): string
    {
        return 'secret_manager';
    }

    public function map(): FieldMap
    {
        return new FieldMap('secret_manager_links', [
            Field::managed('name', 'secret_manager.name', RemoteSecretReferences::DEFAULT_SOURCE),
            Field::managed('integration_token_id', 'secret_manager.token'),
            Field::managed('settings', 'secret_manager.settings', jsonType: 'object'),
        ], ['secret_manager_links' => ['resourceable_type', 'resourceable_id']]);
    }

    public function read(Model $owner): ?array
    {
        $links = SecretManagerLink::query()
            ->where('resourceable_type', $owner->getMorphClass())
            ->where('resourceable_id', $owner->getKey())
            ->with('integrationToken')
            ->get()
            ->filter(fn (SecretManagerLink $link) => $link->integrationToken !== null)
            ->map(fn (SecretManagerLink $link) => [
                'name' => $link->sourceName(),
                'token' => $link->integrationToken->name,
                'settings' => $link->settings ?? [],
            ])
            ->values()
            ->all();

        return $this->canonical($links);
    }

    /**
     * The object form when the only source is `default`, so files with one source stay as they were; otherwise a list sorted by name.
     */
    public function canonical(mixed $desired): ?array
    {
        $sources = self::sources($desired);
        if ($sources === []) {
            return null;
        }

        $entries = [];
        foreach ($sources as $source) {
            $settings = $source['settings'];
            ksort($settings);
            $entry = ['name' => $source['name'], 'token' => $source['token']];
            if ($settings !== []) {
                $entry['settings'] = $settings;
            }
            $entries[] = $entry;
        }
        usort($entries, fn (array $a, array $b) => strcmp($a['name'], $b['name']));

        if (count($entries) === 1 && $entries[0]['name'] === RemoteSecretReferences::DEFAULT_SOURCE) {
            unset($entries[0]['name']);

            return $entries[0];
        }

        return $entries;
    }

    public function schema(): array
    {
        $source = [
            'type' => 'object',
            'required' => ['token'],
            'additionalProperties' => false,
            'properties' => ['token' => ['type' => 'string'], 'settings' => ['type' => 'object']],
        ];

        return [
            'oneOf' => [
                $source,
                [
                    'type' => 'array',
                    'items' => [
                        ...$source,
                        'required' => ['name', 'token'],
                        'properties' => [
                            'name' => ['type' => 'string', 'pattern' => trim(RemoteSecretReferences::SOURCE_NAME_PATTERN, '/')],
                            ...$source['properties'],
                        ],
                    ],
                ],
            ],
        ];
    }

    public function write(Model $owner, mixed $desired, ApplyContext $ctx): void
    {
        $sources = self::sources($desired);
        $owner->secretManagerLinks()->whereNotIn('name', array_column($sources, 'name'))->delete();

        foreach ($sources as $source) {
            $token = NamedCredentials::find('token', $source['token'], $ctx->teamId)
                ?? throw new LogicException("Integration token \"{$source['token']}\" not found.");
            $settings = array_filter($source['settings'], fn ($value) => filled($value));
            $owner->secretManagerLinkNamed($source['name'])->updateOrCreate([], [
                'integration_token_id' => $token->id,
                'settings' => $settings ?: null,
            ]);
        }
    }

    /**
     * Problems with the `secret_manager` value of a resource: list entries without a valid or unique name, and tokens
     * that do not select one integration token of the team.
     *
     * @return list<ConfigError>
     */
    public static function errors(string $base, mixed $desired, int $teamId): array
    {
        if (! is_array($desired)) {
            return [];
        }

        $errors = [];
        $entries = array_is_list($desired) ? $desired : [$desired];
        $paths = array_is_list($desired) ? array_map(fn (int $index) => "{$base}.secret_manager.{$index}", array_keys($desired)) : ["{$base}.secret_manager"];
        $names = [];
        foreach ($entries as $index => $entry) {
            $path = $paths[$index];
            if (! is_array($entry)) {
                $errors[] = new ConfigError($path, 'Use an object with a token.');

                continue;
            }
            if (array_is_list($desired)) {
                $name = $entry['name'] ?? null;
                if (! RemoteSecretReferences::isValidSourceName($name)) {
                    $errors[] = new ConfigError("{$path}.name", 'Use a name of lowercase letters, digits, - and _ (up to 63 characters), starting with a letter or digit.');
                } elseif (isset($names[$name])) {
                    $errors[] = new ConfigError("{$path}.name", "The source name \"{$name}\" is used twice.");
                } else {
                    $names[$name] = true;
                }
            }
            $token = $entry['token'] ?? null;
            if (! is_string($token)) {
                $errors[] = new ConfigError("{$path}.token", 'Name an integration token of this team.');
            } elseif (($message = NamedCredentials::error('token', $token, $teamId)) !== null) {
                $errors[] = new ConfigError("{$path}.token", $message);
            }
        }

        return $errors;
    }

    /**
     * Variables that read a source the file does not link. Without this check, the plan passes and the next deployment fails.
     * `vault.KEY` needs the `default` source once the file links any source.
     *
     * @return list<ConfigError>
     */
    public static function referenceErrors(string $base, mixed $desired, mixed $env): array
    {
        $names = array_column(self::sources($desired), 'name');
        $errors = [];
        foreach ((array) $env as $key => $entry) {
            $ref = is_array($entry) ? ($entry['ref'] ?? null) : null;
            if (! is_string($ref) || ! str_starts_with($ref, 'vault.')) {
                continue;
            }
            $parts = explode('.', $ref);
            $source = count($parts) === 3 ? $parts[1] : RemoteSecretReferences::DEFAULT_SOURCE;
            if (count($parts) === 2 && $names === []) {
                continue;
            }
            if (! in_array($source, $names, true)) {
                $errors[] = new ConfigError("{$base}.env.{$key}.ref", "The secret_manager of this resource has no source named \"{$source}\".");
            }
        }

        return $errors;
    }

    /**
     * The paths of the integration tokens that the value names, for the team credential check.
     *
     * @return list<string> Paths relative to the resource, such as `secret_manager.token` or `secret_manager.1.token`
     */
    public static function tokenPaths(mixed $desired): array
    {
        if (! is_array($desired)) {
            return [];
        }
        if (! array_is_list($desired)) {
            return isset($desired['token']) ? ['secret_manager.token'] : [];
        }

        $paths = [];
        foreach ($desired as $index => $entry) {
            if (is_array($entry) && isset($entry['token'])) {
                $paths[] = "secret_manager.{$index}.token";
            }
        }

        return $paths;
    }

    /**
     * Both file forms as one list. Entries without a token are left out.
     *
     * @return list<array{name: string, token: string, settings: array<string, mixed>}>
     */
    private static function sources(mixed $desired): array
    {
        if (! is_array($desired) || $desired === []) {
            return [];
        }

        $entries = array_is_list($desired)
            ? $desired
            : [['name' => RemoteSecretReferences::DEFAULT_SOURCE] + $desired];

        $sources = [];
        foreach ($entries as $entry) {
            if (! is_array($entry) || ! isset($entry['token'])) {
                continue;
            }
            $name = (string) ($entry['name'] ?? '');
            $sources[$name] = ['name' => $name, 'token' => (string) $entry['token'], 'settings' => (array) ($entry['settings'] ?? [])];
        }

        return array_values($sources);
    }
}
