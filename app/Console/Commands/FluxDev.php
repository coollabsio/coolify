<?php

namespace App\Console\Commands;

use Firebase\JWT\JWT;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

class FluxDev extends Command
{
    protected $signature = 'flux:dev
        {host_id=coold-dev : Stable coold host id}
        {--caps=coold : Comma-separated host capabilities}
        {--ttl=3600 : Token lifetime in seconds}
        {--output= : Optional path to write the token with 0600 permissions}
        {--force : Allow running outside local/development environments}';

    protected $description = 'Run Flux development helpers.';

    public function handle(): int
    {
        if (! app()->environment(['local', 'development', 'testing']) && ! $this->option('force')) {
            $this->error('This command is intended for development only. Use --force to override.');

            return self::FAILURE;
        }

        $privateKeyPath = config('flux.jwt_private_key_path');

        if (! is_string($privateKeyPath) || $privateKeyPath === '' || ! File::isReadable($privateKeyPath)) {
            $this->error("Flux JWT private key not found at {$privateKeyPath}.");

            return self::FAILURE;
        }

        $hostId = (string) $this->argument('host_id');
        $ttl = max(60, (int) $this->option('ttl'));
        $now = time();
        $caps = collect(explode(',', (string) $this->option('caps')))
            ->map(fn (string $cap) => trim($cap))
            ->filter()
            ->unique()
            ->values()
            ->all();

        if ($caps === []) {
            $caps = ['coold'];
        }

        $token = JWT::encode([
            'sub' => $hostId,
            'aud' => 'coold',
            'caps' => $caps,
            'iat' => $now,
            'exp' => $now + $ttl,
        ], File::get($privateKeyPath), 'ES256');

        $output = $this->option('output');

        if (is_string($output) && $output !== '') {
            $outputPath = Str::startsWith($output, '/') ? $output : base_path($output);
            File::ensureDirectoryExists(dirname($outputPath));
            File::put($outputPath, $token.PHP_EOL);
            chmod($outputPath, 0600);
            $this->info("Host JWT written to {$outputPath}.");

            return self::SUCCESS;
        }

        $this->line($token);

        return self::SUCCESS;
    }
}
