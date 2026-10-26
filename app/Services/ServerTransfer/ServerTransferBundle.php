<?php

namespace App\Services\ServerTransfer;

use App\Support\ValidationPatterns;
use Closure;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

class ServerTransferBundle
{
    public const SCHEMA_VERSION = 1;

    /**
     * Every `uuid`, `*_uuid`, and `*_uuids` value in a bundle. Coolify ids are random lowercase
     * letters and digits (new_public_id) or RFC 4122 UUIDs. The ids go into file paths and local
     * shell commands (SSH key file, SSH mux socket, project icon directory).
     */
    public const ID_PATTERN = '/\A[a-zA-Z0-9][a-zA-Z0-9_-]{0,63}\z/';

    /**
     * The most items a bundle can have for each list key, summed over the whole bundle.
     *
     * @var array<string, int>
     */
    public const MAX_ITEMS = [
        'projects' => 1000,
        'environments' => 5000,
        'applications' => 5000,
        'databases' => 5000,
        'services' => 2000,
        'previews' => 10000,
        'environment_variables' => 50000,
        'environment_variables_preview' => 50000,
        'shared_environment_variables' => 20000,
        'persistent_storages' => 20000,
        'file_storages' => 20000,
        'scheduled_tasks' => 10000,
        'scheduled_backups' => 10000,
        'volume_backups' => 10000,
        'tags' => 20000,
        'resource_links' => 10000,
        'destinations' => 1000,
        'private_keys' => 1000,
        'github_apps' => 1000,
        'gitlab_apps' => 1000,
        'gitea_apps' => 1000,
        's3_storages' => 1000,
        'cloud_provider_tokens' => 1000,
        'integration_tokens' => 1000,
        'dns_zones' => 1000,
        'managed_dns_records' => 10000,
        'ssl_certificates' => 10000,
        'warnings' => 1000,
        'post_transfer_checklist' => 1000,
    ];

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function wrap(array $payload): array
    {
        return array_merge([
            'schema_version' => self::SCHEMA_VERSION,
            'coolify_version' => config('constants.coolify.version'),
            'exported_at' => now()->toIso8601String(),
            'export_id' => new_public_id(),
        ], $payload);
    }

    /**
     * @param  array<string, mixed>  $bundle
     * @return array{valid: bool, errors: list<string>, warnings: list<string>}
     */
    public static function validate(array $bundle): array
    {
        $errors = [];
        $warnings = [];

        if (! isset($bundle['schema_version'])) {
            $errors[] = 'Missing schema_version.';
        } elseif ((int) $bundle['schema_version'] !== self::SCHEMA_VERSION) {
            $errors[] = 'Unsupported schema_version '.$bundle['schema_version'].'. Expected '.self::SCHEMA_VERSION.'.';
        }

        $bundleVersion = data_get($bundle, 'coolify_version');
        $instanceVersion = (string) config('constants.coolify.version');
        if (! is_string($bundleVersion) || $bundleVersion === '') {
            $errors[] = 'Missing coolify_version. Export the server again with Coolify '.$instanceVersion.' or newer.';
        } elseif (version_compare($bundleVersion, $instanceVersion, '>')) {
            $errors[] = "This bundle was exported from Coolify {$bundleVersion}. Upgrade this instance (Coolify {$instanceVersion}) to {$bundleVersion} or newer before you import it.";
        }

        if (! isset($bundle['export_id']) || ! is_string($bundle['export_id']) || $bundle['export_id'] === '') {
            $errors[] = 'Missing export_id.';
        }

        if (! isset($bundle['server']) || ! is_array($bundle['server'])) {
            $errors[] = 'Missing server payload.';
        } else {
            foreach (['uuid', 'name', 'ip', 'port', 'user'] as $field) {
                if (! array_key_exists($field, $bundle['server'])) {
                    $errors[] = "Missing server.{$field}.";
                }
            }
        }

        if (! isset($bundle['private_key']) || ! is_array($bundle['private_key'])) {
            $errors[] = 'Missing private_key payload.';
        } else {
            if (blank(data_get($bundle, 'private_key.private_key'))) {
                $errors[] = 'Missing private_key.private_key material.';
            }
        }

        if (! isset($bundle['destinations']) || ! is_array($bundle['destinations'])) {
            $errors[] = 'Missing destinations array.';
        } elseif (count($bundle['destinations']) === 0) {
            $warnings[] = 'Bundle has no destinations; a default destination will be used.';
        }

        if (! isset($bundle['projects']) || ! is_array($bundle['projects'])) {
            $errors[] = 'Missing projects array.';
        }

        $errors = array_merge($errors, self::contentErrors($bundle));

        return [
            'valid' => $errors === [],
            'errors' => $errors,
            'warnings' => $warnings,
        ];
    }

    /**
     * @param  array<string, mixed>  $bundle
     */
    public static function assertValid(array $bundle): void
    {
        $result = self::validate($bundle);
        if (! $result['valid']) {
            throw ValidationException::withMessages([
                'bundle' => $result['errors'],
            ]);
        }
    }

    /**
     * Checks the values of a bundle before the import writes anything. A bundle comes from the
     * API, so each value gets the validation that the normal API applies to it.
     *
     * @param  array<string, mixed>  $bundle
     * @return list<string>
     */
    public static function contentErrors(array $bundle): array
    {
        $errors = [];
        $counts = [];
        self::walk($bundle, '', $errors, $counts);

        foreach ($counts as $key => $count) {
            if ($count > self::MAX_ITEMS[$key]) {
                $errors[] = "The bundle has {$count} {$key}. A bundle can have at most ".self::MAX_ITEMS[$key]." {$key}.";
            }
        }

        // Stop before the field checks: a bundle with a bad id or too many items is not imported.
        if ($errors !== []) {
            return $errors;
        }

        foreach (self::items(data_get($bundle, 'destinations')) as $i => $destination) {
            self::check($errors, "destinations.{$i}.network", $destination['network'] ?? null, ['nullable', 'string', 'max:255', 'regex:'.ValidationPatterns::DOCKER_NETWORK_PATTERN]);
        }

        // Only http and https URLs, as for a git source made with the API. The import does not resolve the host:
        // the target may not reach the git host yet, and SafeExternalUrl checks the host on every request.
        $gitSourceUrl = function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && ! isValidDomainUrl($value)) {
                $fail('The :attribute must use the http or https scheme.');
            }
        };
        foreach (['github_apps', 'gitlab_apps', 'gitea_apps'] as $key) {
            foreach (self::items(data_get($bundle, $key)) as $i => $source) {
                foreach (['html_url', 'api_url'] as $field) {
                    self::check($errors, "{$key}.{$i}.{$field}", $source[$field] ?? null, ['nullable', 'string', 'url', $gitSourceUrl]);
                }
            }
        }

        foreach (self::items(data_get($bundle, 'ssl_certificates')) as $i => $certificate) {
            self::checkPath($errors, "ssl_certificates.{$i}.configuration_dir", $certificate['configuration_dir'] ?? null);
            self::checkPath($errors, "ssl_certificates.{$i}.mount_path", $certificate['mount_path'] ?? null);
        }

        foreach (self::items(data_get($bundle, 'volume_backups')) as $i => $backup) {
            self::checkCron($errors, "volume_backups.{$i}.frequency", $backup['frequency'] ?? null);
        }

        foreach (self::items(data_get($bundle, 'projects')) as $p => $project) {
            foreach (self::items($project['environments'] ?? null) as $e => $environment) {
                $at = "projects.{$p}.environments.{$e}";

                foreach (self::items($environment['applications'] ?? null) as $a => $application) {
                    self::checkDomains($errors, "{$at}.applications.{$a}.fqdn", data_get($application, 'attributes.fqdn'));
                    self::checkStorages($errors, "{$at}.applications.{$a}", $application);
                    self::checkScheduledTasks($errors, "{$at}.applications.{$a}", $application);
                    foreach (self::items($application['previews'] ?? null) as $r => $preview) {
                        self::checkDomains($errors, "{$at}.applications.{$a}.previews.{$r}.fqdn", $preview['fqdn'] ?? null);
                        self::checkStorages($errors, "{$at}.applications.{$a}.previews.{$r}", $preview);
                    }
                }

                foreach (self::items($environment['databases'] ?? null) as $d => $database) {
                    self::checkStorages($errors, "{$at}.databases.{$d}", $database);
                    self::checkScheduledBackups($errors, "{$at}.databases.{$d}", $database);
                }

                foreach (self::items($environment['services'] ?? null) as $s => $service) {
                    self::checkScheduledTasks($errors, "{$at}.services.{$s}", $service);
                    foreach (self::items($service['applications'] ?? null) as $a => $serviceApplication) {
                        self::checkDomains($errors, "{$at}.services.{$s}.applications.{$a}.fqdn", data_get($serviceApplication, 'attributes.fqdn', $serviceApplication['fqdn'] ?? null));
                        self::checkStorages($errors, "{$at}.services.{$s}.applications.{$a}", $serviceApplication);
                    }
                    foreach (self::items($service['databases'] ?? null) as $d => $serviceDatabase) {
                        self::checkStorages($errors, "{$at}.services.{$s}.databases.{$d}", $serviceDatabase);
                        self::checkScheduledBackups($errors, "{$at}.services.{$s}.databases.{$d}", $serviceDatabase);
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * Checks every id in the bundle and counts the items of every list key in MAX_ITEMS.
     *
     * @param  list<string>  $errors
     * @param  array<string, int>  $counts
     */
    private static function walk(array $node, string $path, array &$errors, array &$counts): void
    {
        foreach ($node as $key => $value) {
            $at = $path === '' ? (string) $key : "{$path}.{$key}";

            if (is_string($key)) {
                if ($key === 'uuid' || str_ends_with($key, '_uuid')) {
                    self::checkId($errors, $at, $value);
                } elseif (str_ends_with($key, '_uuids')) {
                    foreach (is_array($value) ? $value : [$value] as $i => $id) {
                        self::checkId($errors, "{$at}.{$i}", $id);
                    }

                    continue;
                }

                if (isset(self::MAX_ITEMS[$key]) && is_array($value)) {
                    $counts[$key] = ($counts[$key] ?? 0) + (array_is_list($value)
                        ? count($value)
                        : array_sum(array_map(fn ($list) => is_array($list) ? count($list) : 0, $value)));
                }
            }

            if (is_array($value)) {
                self::walk($value, $at, $errors, $counts);
            }
        }
    }

    /**
     * @param  list<string>  $errors
     */
    private static function checkId(array &$errors, string $at, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }

        if (! is_string($value) || preg_match(self::ID_PATTERN, $value) !== 1) {
            $errors[] = "{$at} is not a valid id. An id may only contain letters, digits, hyphens, and underscores (at most 64 characters).";
        }
    }

    /**
     * @return array<int|string, array<string, mixed>>
     */
    private static function items(mixed $value): array
    {
        return is_array($value) ? array_filter($value, 'is_array') : [];
    }

    /**
     * @param  list<string>  $errors
     * @param  array<int, mixed>  $rules
     */
    private static function check(array &$errors, string $at, mixed $value, array $rules): void
    {
        $validator = Validator::make(['value' => $value], ['value' => $rules], [], ['value' => $at]);
        foreach ($validator->errors()->all() as $message) {
            $errors[] = $message;
        }
    }

    /**
     * @param  list<string>  $errors
     */
    private static function checkDomains(array &$errors, string $at, mixed $value): void
    {
        foreach (ValidationPatterns::validateApplicationDomains($value) as $message) {
            $errors[] = "{$at}: {$message}";
        }
    }

    /**
     * @param  list<string>  $errors
     */
    private static function checkPath(array &$errors, string $at, mixed $value): void
    {
        if ($value === null || $value === '') {
            return;
        }
        if (! is_string($value)) {
            $errors[] = "{$at} must be a string.";

            return;
        }

        try {
            validateShellSafePath($value, $at);
        } catch (Throwable $e) {
            $errors[] = $e->getMessage();
        }
    }

    /**
     * @param  list<string>  $errors
     */
    private static function checkCron(array &$errors, string $at, mixed $value): void
    {
        if ($value === null) {
            return;
        }

        try {
            $valid = is_string($value) && validate_cron_expression($value);
        } catch (Throwable) {
            $valid = false;
        }
        if (! $valid) {
            $errors[] = "{$at} is not a valid cron expression or frequency.";
        }
    }

    /**
     * @param  list<string>  $errors
     * @param  array<string, mixed>  $resource
     */
    private static function checkStorages(array &$errors, string $at, array $resource): void
    {
        foreach (self::items($resource['persistent_storages'] ?? null) as $i => $volume) {
            self::checkPath($errors, "{$at}.persistent_storages.{$i}.mount_path", $volume['mount_path'] ?? null);
            self::checkPath($errors, "{$at}.persistent_storages.{$i}.host_path", $volume['host_path'] ?? null);
        }
        foreach (self::items($resource['file_storages'] ?? null) as $i => $storage) {
            self::checkPath($errors, "{$at}.file_storages.{$i}.fs_path", $storage['fs_path'] ?? null);
            self::checkPath($errors, "{$at}.file_storages.{$i}.mount_path", $storage['mount_path'] ?? null);
        }
    }

    /**
     * The same checks as the scheduled task API.
     *
     * @param  list<string>  $errors
     * @param  array<string, mixed>  $resource
     */
    private static function checkScheduledTasks(array &$errors, string $at, array $resource): void
    {
        foreach (self::items($resource['scheduled_tasks'] ?? null) as $i => $task) {
            self::check($errors, "{$at}.scheduled_tasks.{$i}.command", $task['command'] ?? null, ['required', 'string']);
            self::check($errors, "{$at}.scheduled_tasks.{$i}.container", $task['container'] ?? null, ['nullable', ...ValidationPatterns::containerNameRules()]);
            self::checkCron($errors, "{$at}.scheduled_tasks.{$i}.frequency", $task['frequency'] ?? '');
        }
    }

    /**
     * @param  list<string>  $errors
     * @param  array<string, mixed>  $database
     */
    private static function checkScheduledBackups(array &$errors, string $at, array $database): void
    {
        foreach (self::items($database['scheduled_backups'] ?? null) as $i => $backup) {
            self::checkCron($errors, "{$at}.scheduled_backups.{$i}.frequency", $backup['frequency'] ?? null);
            self::checkPath($errors, "{$at}.scheduled_backups.{$i}.databases_to_backup", $backup['databases_to_backup'] ?? null);
        }
    }
}
