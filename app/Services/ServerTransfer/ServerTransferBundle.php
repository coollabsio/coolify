<?php

namespace App\Services\ServerTransfer;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class ServerTransferBundle
{
    public const SCHEMA_VERSION = 1;

    public const CLAIM_PATH = '/data/coolify/instance-claim.json';

    public const MAILBOX_DIR = '/data/coolify/exports';

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function wrap(array $payload): array
    {
        return array_merge([
            'schema_version' => self::SCHEMA_VERSION,
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

    /**
     * Encrypt an entire bundle for mailbox/API transport with a user passphrase.
     *
     * @param  array<string, mixed>  $bundle
     * @return array{encrypted: true, schema_version: int, payload: string}
     */
    public static function encryptWithPassphrase(array $bundle, string $passphrase): array
    {
        if ($passphrase === '') {
            throw new RuntimeException('Passphrase must not be empty.');
        }

        $json = json_encode($bundle, JSON_THROW_ON_ERROR);
        $key = hash('sha256', $passphrase, true);
        $iv = random_bytes(16);
        $cipher = openssl_encrypt($json, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($cipher === false) {
            throw new RuntimeException('Failed to encrypt transfer bundle.');
        }

        $mac = hash_hmac('sha256', $iv.$cipher, $key);

        return [
            'encrypted' => true,
            'schema_version' => self::SCHEMA_VERSION,
            'payload' => base64_encode($iv.$mac.$cipher),
        ];
    }

    /**
     * @param  array<string, mixed>  $encrypted
     * @return array<string, mixed>
     */
    public static function decryptWithPassphrase(array $encrypted, string $passphrase): array
    {
        if (! data_get($encrypted, 'encrypted')) {
            throw new RuntimeException('Bundle is not encrypted.');
        }

        $raw = base64_decode((string) data_get($encrypted, 'payload'), true);
        if ($raw === false || strlen($raw) < 48) {
            throw new RuntimeException('Invalid encrypted payload.');
        }

        $key = hash('sha256', $passphrase, true);
        $iv = substr($raw, 0, 16);
        $mac = substr($raw, 16, 64);
        $cipher = substr($raw, 80);
        $expectedMac = hash_hmac('sha256', $iv.$cipher, $key);
        if (! hash_equals($expectedMac, $mac)) {
            throw new RuntimeException('Invalid passphrase or corrupted bundle.');
        }

        $json = openssl_decrypt($cipher, 'AES-256-CBC', $key, OPENSSL_RAW_DATA, $iv);
        if ($json === false) {
            throw new RuntimeException('Failed to decrypt transfer bundle.');
        }

        /** @var array<string, mixed> $bundle */
        $bundle = json_decode($json, true, 512, JSON_THROW_ON_ERROR);

        return $bundle;
    }

    /**
     * Optionally re-seal secrets with Laravel's app key for intermediate storage.
     * Prefer passphrase encryption for cross-instance transfers.
     *
     * @param  array<string, mixed>  $bundle
     */
    public static function sealWithAppKey(array $bundle): string
    {
        return Crypt::encryptString(json_encode($bundle, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, mixed>
     */
    public static function unsealWithAppKey(string $sealed): array
    {
        /** @var array<string, mixed> $bundle */
        $bundle = json_decode(Crypt::decryptString($sealed), true, 512, JSON_THROW_ON_ERROR);

        return $bundle;
    }
}
