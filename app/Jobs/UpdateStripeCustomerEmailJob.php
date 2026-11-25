<?php

namespace App\Jobs;

use App\Models\Team;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Stripe\Stripe;

class UpdateStripeCustomerEmailJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public $backoff = [10, 30, 60];

    public function __construct(
        private Team $team,
        private int $userId,
        private string $newEmail,
        private string $oldEmail
    ) {
        $this->onQueue('high');
    }

    public function handle(): void
    {
        try {
            if (! isCloud() || ! $this->team->subscription) {
                Log::info('Skipping Stripe email update - not cloud or no subscription', [
                    'team_id' => $this->team->id,
                    'user_id' => $this->userId,
                ]);

                return;
            }

            // Check if the user changing email is a team owner
            $isOwner = $this->team->members()
                ->wherePivot('role', 'owner')
                ->where('users.id', $this->userId)
                ->exists();

            if (! $isOwner) {
                Log::info('Skipping Stripe email update - user is not team owner', [
                    'team_id' => $this->team->id,
                    'user_id' => $this->userId,
                ]);

                return;
            }

            // Get current Stripe customer email to verify it matches the user's old email
            $stripe_customer_id = data_get($this->team, 'subscription.stripe_customer_id');
            if (! $stripe_customer_id) {
                Log::info('Skipping Stripe email update - no Stripe customer ID', [
                    'team_id' => $this->team->id,
                    'user_id' => $this->userId,
                ]);

                return;
            }

            Stripe::setApiKey(config('subscription.stripe_api_key'));

            try {
                $stripeCustomer = \Stripe\Customer::retrieve($stripe_customer_id);
                $currentStripeEmail = $stripeCustomer->email;

                // Only update if the current Stripe email matches the user's old email
                if (strtolower($currentStripeEmail) !== strtolower($this->oldEmail)) {
                    Log::info('Skipping Stripe email update - Stripe customer email does not match user old email', [
                        'team_id' => $this->team->id,
                        'user_id' => $this->userId,
                        'stripe_email' => $currentStripeEmail,
                        'user_old_email' => $this->oldEmail,
                    ]);

                    return;
                }

                // Update Stripe customer email
                \Stripe\Customer::update($stripe_customer_id, ['email' => $this->newEmail]);

            } catch (\Exception $e) {
                Log::error('Failed to retrieve or update Stripe customer', [
                    'team_id' => $this->team->id,
                    'user_id' => $this->userId,
                    'stripe_customer_id' => $stripe_customer_id,
                    'error' => $e->getMessage(),
                ]);

                throw $e;
            }

            Log::info('Successfully updated Stripe customer email', [
                'team_id' => $this->team->id,
                'user_id' => $this->userId,
                'old_email' => $this->oldEmail,
                'new_email' => $this->newEmail,
            ]);
        } catch (\Exception $e) {
            Log::error('Failed to update Stripe customer email', [
                'team_id' => $this->team->id,
                'user_id' => $this->userId,
                'old_email' => $this->oldEmail,
                'new_email' => $this->newEmail,
                'error' => $e->getMessage(),
                'attempt' => $this->attempts(),
            ]);

            // Re-throw to trigger retry
            throw $e;
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('Permanently failed to update Stripe customer email after all retries', [
            'team_id' => $this->team->id,
            'user_id' => $this->userId,
            'old_email' => $this->oldEmail,
            'new_email' => $this->newEmail,
            'error' => $exception->getMessage(),
        ]);
    }
}
