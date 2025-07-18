<?php

namespace App\Jobs;

use App\Actions\Server\ResourcesCheck;
use App\Actions\Server\ServerCheck;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class ServerCheckNewJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 60;

    public function __construct(public Server $server) {}

    public function handle()
    {
        try {
            ServerCheck::run($this->server);
            ResourcesCheck::dispatch($this->server);
        } catch (\Throwable $e) {
            return handleError($e);
        }
    }
}
