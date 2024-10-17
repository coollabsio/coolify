<?php

namespace App\Jobs;

use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ServerStorageCheckJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 60;

    public $containers;

    public $applications;

    public $databases;

    public $services;

    public $previews;

    public function backoff(): int
    {
        return isDev() ? 1 : 3;
    }

    public function __construct(public Server $server) {}

    public function handle()
    {
        try {
            if (! $this->server->isFunctional()) {
                ray('Server is not ready.');

                return 'Server is not ready.';
            }
            $team = $this->server->team;
            $percentage = $this->server->storageCheck();
            if ($percentage > 1) {
                ray('Server storage is at '.$percentage.'%');
            }

        } catch (\Throwable $e) {
            ray($e->getMessage());

            return handleError($e);
        }

    }
}
