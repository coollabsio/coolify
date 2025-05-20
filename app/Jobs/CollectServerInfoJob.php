<?php

namespace App\Jobs;

use App\Actions\Server\CollectServerInfo;
use App\Models\Server;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class CollectServerInfoJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $server;

    /**
     * Create a new job instance.
     */
    public function __construct(Server $server)
    {
        $this->server = $server;
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        try {
            // Use the CollectServerInfo action to gather server information
            CollectServerInfo::run($this->server);

            // Broadcast an event to notify the UI that the server info has been updated
            event(new \App\Events\ServerInfoUpdated($this->server));
        } catch (\Throwable $e) {
            // Log the error but don't rethrow it to prevent the job from retrying
            \Illuminate\Support\Facades\Log::error('Error collecting server info: ' . $e->getMessage());
        }
    }
}
