<?php

namespace App\Console\Commands;

use App\Actions\Sentinel\RenewFluxCertificate as RenewCertificate;
use Illuminate\Console\Command;
use Throwable;

class RenewFluxCertificate extends Command
{
    protected $signature = 'flux:renew-certificate';

    protected $description = 'Renew the Flux TLS certificate when it has 30 days remaining';

    public function handle(): int
    {
        if (! config('constants.sentinel.host_enabled')) {
            return self::SUCCESS;
        }

        try {
            $renewed = RenewCertificate::run();
            $this->info($renewed ? 'Flux certificate renewed.' : 'Flux certificate renewal is not required or is already running.');

            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('Flux certificate renewal failed. Check the application log.');

            return self::FAILURE;
        }
    }
}
