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
            ->whereNull('api_token_expiration_warning_sent_at')
            ->where('tokenable_type', User::class)
            ->chunkById(100, function ($tokens) {
                foreach ($tokens as $token) {
                    if (! $token->team_id) {
                        continue;
                    }

                    $team = Team::find($token->team_id);
                    if (! $team) {
                        continue;
                    }

                    $warningSentAt = now();

                    $team->notify(new ApiTokenExpiringNotification($token));

                    $markedAsSent = PersonalAccessToken::query()
                        ->whereKey($token->getKey())
                        ->whereNotNull('expires_at')
                        ->where('expires_at', '>', now())
                        ->where('expires_at', '<=', now()->addDay())
                        ->whereNull('api_token_expiration_warning_sent_at')
                        ->update(['api_token_expiration_warning_sent_at' => $warningSentAt]);

                    if ($markedAsSent !== 1) {
                        continue;
                    }

                    $token->forceFill(['api_token_expiration_warning_sent_at' => $warningSentAt]);
                }
            });
    }
}
