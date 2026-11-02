<?php

namespace App\Jobs;

use App\Models\Server;
use App\Services\ServerTransfer\TransferFinishChecklist;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Checks the open steps of the "Finish the transfer" list in the background, so the page stays fast while
 * checks call SSH, GitHub, Cloudflare, DNS, and S3. One run per server at a time.
 */
class CheckTransferFinishStepsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 1;

    public int $timeout = 120;

    public int $uniqueFor = 120;

    public function __construct(public Server $server) {}

    public function uniqueId(): string
    {
        return (string) $this->server->id;
    }

    public function handle(TransferFinishChecklist $checklist): void
    {
        $checklist->checkAll($this->server);
    }
}
