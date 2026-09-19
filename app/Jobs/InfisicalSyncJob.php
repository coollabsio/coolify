<?php

namespace App\Jobs;

use App\Actions\Infisical\SyncEnvironmentSecrets;
use App\Models\InfisicalBinding;
use App\Services\Infisical\InfisicalApiException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class InfisicalSyncJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public InfisicalBinding $binding)
    {
        $this->onQueue(crons_queue());
    }

    /**
     * Prevent two syncs of the same binding from racing each other.
     */
    public function middleware(): array
    {
        return [new WithoutOverlapping('infisical-sync-'.$this->binding->id)];
    }

    public function handle(): void
    {
        try {
            SyncEnvironmentSecrets::run($this->binding);
        } catch (InfisicalApiException $e) {
            // A broken binding must not fail the whole scheduled batch. The
            // failure is recorded on the binding by the action itself.
            Log::warning('Infisical sync failed', [
                'binding_id' => $this->binding->id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
