<?php

namespace App\Services\ServerTransfer;

use Illuminate\Validation\ValidationException;

class ServerTransferBundle
{
    public const SCHEMA_VERSION = 1;

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
}
