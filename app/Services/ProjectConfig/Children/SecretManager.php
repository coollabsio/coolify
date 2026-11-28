<?php

namespace App\Services\ProjectConfig\Children;

use App\Models\IntegrationToken;
use App\Models\SecretManagerLink;
use App\Services\ProjectConfig\ApplyContext;
use App\Services\ProjectConfig\ConfigError;
use App\Services\ProjectConfig\Field;
use App\Services\ProjectConfig\FieldMap;
use App\Services\ProjectConfig\NamedCredentials;
use App\Services\SecretManagers\ResourceSecretManager;
use App\Support\RemoteSecretReferences;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use LogicException;

/**
 * The external secret manager sources of a resource: a team integration token by name, plus its settings.
 * The object form `{token, settings}` is the `default` source ({{vault.KEY}}). The list form
 * `[{name, token, settings}, ...]` names several sources ({{vault.<name>.KEY}}).
 */
class SecretManager implements ChildRecords
{
    /**
     * The settings keys of all providers (ResourceSecretManager::settingsRules()). The schema rejects other keys,
     * and the plan checks these per provider.
     */
    private const SETTINGS_KEYS = ['project', 'config', 'project_id', 'environment', 'secret_path', 'mount', 'path'];

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
            // write() does not store empty values, so they are not part of the state.
            $settings = array_filter($source['settings'], fn ($value) => filled($value));
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
            'properties' => ['token' => ['type' => 'string'], 'settings' => [
                'type' => 'object',
                'additionalProperties' => false,
                'properties' => array_fill_keys(self::SETTINGS_KEYS, ['type' => 'string']),
            ]],
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
            } else {
                $errors = [...$errors, ...self::settingsErrors($path, $entry['settings'] ?? [], NamedCredentials::find('token', $token, $teamId))];
            }
        }

        return $errors;
    }

    /**
     * Problems with the provider settings of a source, with the rules that the API and the resource page use.
     * Without this check, a wrong key such as `secretPath` is stored and the deployment reads another folder.
     *
     * @return list<ConfigError>
     */
    private static function settingsErrors(string $path, mixed $settings, IntegrationToken $token): array
    {
        if (! is_array($settings) || ($settings !== [] && array_is_list($settings))) {
            return [new ConfigError("{$path}.settings", 'Use an object of provider settings.')];
        }

        $rules = app(ResourceSecretManager::class)->settingsRules($token);
        $known = array_map(fn (string $field): string => Str::after($field, 'settings.'), array_keys($rules));
        $errors = [];
        foreach (array_intersect(array_keys($settings), self::SETTINGS_KEYS) as $field) {
            if (! in_array($field, $known, true)) {
                $hint = $known === [] ? 'This token takes no settings.' : 'Use one of: '.implode(', ', $known).'.';
                $errors[] = new ConfigError("{$path}.settings.{$field}", "Unknown {$token->provider} setting. {$hint}");
            }
        }
        $validator = Validator::make(['settings' => $settings], $rules);
        foreach ($validator->errors()->messages() as $field => $messages) {
            $errors[] = new ConfigError("{$path}.{$field}", $messages[0]);
        }

        return $errors;
    }

    /**
     * Variables that read a source the file does not link, or that hold text which looks like a vault reference
     * but is not one. Without this check, the plan passes and the next deployment fails or keeps the text.
     * `vault.KEY` needs the `default` source once the file links any source.
     *
     * @param  bool  $checkRefs  False for resources that reject vault refs with their own error
     * @return list<ConfigError>
     */
    public static function referenceErrors(string $base, mixed $desired, mixed $env, bool $checkRefs = true): array
    {
        $names = array_column(self::sources($desired), 'name');
        $errors = [];
        foreach ((array) $env as $key => $entry) {
            if (! is_array($entry)) {
                continue;
            }
            foreach (['' => $entry, '.preview' => $entry['preview'] ?? null] as $suffix => $variant) {
                if (! is_array($variant)) {
                    continue;
                }
                $texts = ['value' => is_string($variant['value'] ?? null) ? $variant['value'] : null];
                if ($checkRefs && is_string($variant['ref'] ?? null)) {
                    $texts['ref'] = '{{'.$variant['ref'].'}}';
                }
                foreach ($texts as $field => $text) {
                    $path = "{$base}.env.{$key}{$suffix}.{$field}";
                    foreach (RemoteSecretReferences::malformed($text) as $malformed) {
                        $errors[] = new ConfigError($path, "\"{$malformed}\" is not a vault reference. Use {{vault.KEY}} or {{vault.<source>.KEY}} with a lowercase source name.");
                    }
                    foreach (RemoteSecretReferences::referencedSources($text) as $source) {
                        if ($source === RemoteSecretReferences::DEFAULT_SOURCE && $names === []) {
                            continue;
                        }
                        if (! in_array($source, $names, true)) {
                            $errors[] = new ConfigError($path, "The secret_manager of this resource has no source named \"{$source}\".");
                        }
                    }
                }
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
