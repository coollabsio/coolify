<?php

namespace App\Console\Commands;

use App\Services\Flux\AgentTokenIssuer;
use App\Support\V5\V5Feature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Generates the ES256 (EC P-256) keypair used to authorize coold host agents
 * against flux. Laravel signs the per-host JWT with the private key
 * (config('flux.jwt_private_key_path')); flux verifies it with the matching
 * public key (config('flux.jwt_public_key_path')). Without this keypair a fresh
 * install cannot mint host tokens, so this command is a bootstrap prerequisite.
 *
 * @see AgentTokenIssuer
 */
class V5FluxGenerateKeys extends Command
{
    protected $signature = 'v5:flux-generate-keys
        {--force : Overwrite an existing private key instead of refusing}
        {--show-public : Print the generated public key PEM so it can be provisioned to flux}';

    protected $description = 'Generate the ES256 keypair Flux uses to sign and verify coold host agent JWTs.';

    public function handle(AgentTokenIssuer $agentTokenIssuer): int
    {
        if (! V5Feature::enabled()) {
            $this->error('V5 is only available in development environments.');

            return self::FAILURE;
        }

        $privateKeyPath = (string) config('flux.jwt_private_key_path');
        $publicKeyPath = (string) config('flux.jwt_public_key_path');

        if ($privateKeyPath === '' || $publicKeyPath === '') {
            $this->error('Flux JWT key paths are not configured (flux.jwt_private_key_path / flux.jwt_public_key_path).');

            return self::FAILURE;
        }

        // Idempotent by default: re-running during provisioning must not clobber
        // a live key (which would instantly invalidate every host token on
        // disk). Refuse unless --force is passed, and exit SUCCESS so a
        // provisioning script can call this unconditionally on every deploy.
        if (File::exists($privateKeyPath) && ! $this->option('force')) {
            $this->warn("A Flux JWT private key already exists at {$privateKeyPath}.");
            $this->line('Refusing to overwrite it. Re-run with --force to replace it (this invalidates every host token currently on disk).');

            return self::SUCCESS;
        }

        // curve_name drives the actual EC key (P-256). private_key_bits is
        // still validated by PHP's generic length check (>= 384) even though it
        // is irrelevant to EC, so it must be present or openssl_pkey_new fails
        // with "Private key length must be at least 384 bits, configured to 0".
        $keyPair = openssl_pkey_new([
            'private_key_type' => OPENSSL_KEYTYPE_EC,
            'curve_name' => 'prime256v1',
            'private_key_bits' => 384,
        ]);

        if ($keyPair === false) {
            $this->error('Failed to generate an EC P-256 keypair: '.openssl_error_string());

            return self::FAILURE;
        }

        $privatePem = '';

        if (! openssl_pkey_export($keyPair, $privatePem)) {
            $this->error('Failed to export the private key PEM: '.openssl_error_string());

            return self::FAILURE;
        }

        $details = openssl_pkey_get_details($keyPair);

        if ($details === false || ! isset($details['key'])) {
            $this->error('Failed to read the generated public key PEM.');

            return self::FAILURE;
        }

        $publicPem = (string) $details['key'];

        $this->writeKeyFile($privateKeyPath, $privatePem, 0600);
        $this->writeKeyFile($publicKeyPath, $publicPem, 0644);

        // Self-check: the whole point of this command is that AgentTokenIssuer
        // can mint with the key we just wrote. If the format were wrong (e.g.
        // not a PEM EC private key Firebase\JWT accepts for ES256) this fails
        // loudly here instead of silently at the first real host bootstrap.
        try {
            $token = $agentTokenIssuer->issue('flux-keygen-selfcheck');
        } catch (\Throwable $exception) {
            $this->error('Generated a keypair but AgentTokenIssuer could not mint a token with it: '.$exception->getMessage());

            return self::FAILURE;
        }

        if (substr_count($token, '.') !== 2) {
            $this->error('Generated key produced a malformed JWT (expected 3 segments).');

            return self::FAILURE;
        }

        $this->info('Generated a fresh ES256 (EC P-256) Flux keypair.');
        $this->line("  Private key (0600): {$privateKeyPath}");
        $this->line("  Public key  (0644): {$publicKeyPath}");
        $this->newLine();
        $this->line('Provision the PUBLIC key to flux — flux verifies every host JWT with it.');
        $this->line('Keep the PRIVATE key secret and on the Laravel host only.');

        if ($this->option('show-public')) {
            $this->newLine();
            $this->line(rtrim($publicPem));
        }

        return self::SUCCESS;
    }

    /**
     * Write a key file with exact permissions, creating the parent directory at
     * 0700 if missing. chmod is applied after the write because umask can
     * loosen both the mkdir mode and the created file mode.
     */
    private function writeKeyFile(string $path, string $contents, int $mode): void
    {
        $directory = dirname($path);

        if (! is_dir($directory)) {
            File::makeDirectory($directory, 0700, true);
            @chmod($directory, 0700);
        }

        File::put($path, $contents);
        @chmod($path, $mode);
    }
}
