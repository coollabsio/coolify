<?php

namespace App\Console\Commands;

use App\Actions\Sentinel\InitializeFluxTls as InitializeTls;
use Illuminate\Console\Command;
use Throwable;

class InitializeFluxTls extends Command
{
    protected $signature = 'flux:initialize-tls {--identities= : Comma-separated Flux DNS names and IP addresses}';

    protected $description = 'Create and materialize the initial Flux TLS certificate';

    public function handle(): int
    {
        $identities = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('identities')))));
        if ($identities === []) {
            $this->error('At least one Flux TLS identity is required.');

            return self::INVALID;
        }

        try {
            $certificate = InitializeTls::run($identities);
            $this->info("Flux TLS certificate version {$certificate->version} is ready.");

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Flux TLS initialization failed. Check the application log.');

            return self::FAILURE;
        }
    }
}
