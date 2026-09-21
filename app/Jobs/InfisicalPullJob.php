<?php

namespace App\Jobs;

use App\Actions\Infisical\PullTeamSecrets;
use App\Models\InfisicalConnection;
use App\Services\Infisical\InfisicalApiException;
use App\Services\Infisical\InfisicalPathCollisionException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * The scheduled downward sync for one connection.
 *
 * ShouldBeEncrypted because the serialised connection reaches machine-identity
 * credentials, matching CoolifyTask.
 */
class InfisicalPullJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public InfisicalConnection $connection)
    {
        $this->onQueue(crons_queue());
    }

    /**
     * Two overlapping pulls of one connection is a real race under Horizon.
     *
     * @return array<int, object>
     */
    public function middleware(): array
    {
        return [new WithoutOverlapping('infisical-pull-'.$this->connection->id)];
    }

    public function handle(): void
    {
        try {
            PullTeamSecrets::run($this->connection);
        } catch (InfisicalApiException|InfisicalPathCollisionException $e) {
            // A scheduled pull must not leave stored values in a broken state,
            // and one broken connection must not fail the whole batch. Record
            // the failure and return.
            $this->connection->forceFill([
                'last_sync_status' => InfisicalConnection::STATUS_FAILED,
                'last_sync_error' => $e->getMessage(),
            ])->save();

            Log::warning('Infisical pull failed', [
                'connection_id' => $this->connection->id,
                'message' => $e->getMessage(),
            ]);
        }
    }
}
