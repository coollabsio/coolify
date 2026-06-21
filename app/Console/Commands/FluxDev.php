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
        {--caps= : Comma-separated host capabilities}
        {--ttl=3600 : Token lifetime in seconds}
        {--output= : Optional path to write the token with 0600 permissions}
        {--force : Allow running outside local/development environments}';

    protected $description = 'Run Flux development helpers.';

    /**
     * @return array<int, string>
     */
    private function defaultCapabilities(): array
    {
        return [
            'images.pull',
            'images.list',
            'images.delete',
            'containers.create',
            'containers.start',
            'containers.stop',
            'containers.restart',
            'containers.delete',
            'containers.inspect',
            'containers.list',
            'containers.logs',
            'containers.exec',
            'containers.healthcheck.run',
            'ingress.apply',
            'ingress.stop',
            'firewall.allow',
            'firewall.revoke',
            'firewall.list',
            'firewall.reconcile',
        ];
    }

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
            $caps = $this->defaultCapabilities();
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
