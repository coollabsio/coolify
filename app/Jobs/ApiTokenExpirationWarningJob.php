<?php

namespace App\Jobs;

use App\Models\PersonalAccessToken;
use App\Models\Team;
use App\Models\User;
use App\Notifications\ApiTokenExpiringNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\RateLimiter;
use Laravel\Horizon\Contracts\Silenced;

class ApiTokenExpirationWarningJob implements ShouldBeEncrypted, ShouldQueue, Silenced
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 1;

    public $timeout = 120;

    public function handle(): void
    {
        PersonalAccessToken::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now())
            ->where('expires_at', '<=', now()->addDay())
            ->where('tokenable_type', User::class)
            ->chunkById(100, function ($tokens) {
                foreach ($tokens as $token) {
                    if (! $token->team_id) {
                        continue;
                    }
                    RateLimiter::attempt(
                        'api-token-expiring:'.$token->id,
                        $maxAttempts = 0,
                        function () use ($token) {
                            Team::find($token->team_id)?->notify(new ApiTokenExpiringNotification($token));
                        },
                        $decaySeconds = 7 * 24 * 3600,
                    );
                }
            });
    }
}
