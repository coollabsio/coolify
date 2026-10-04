<?php

namespace App\Livewire\Subscription;

use App\Actions\Stripe\CancelSubscriptionAtPeriodEnd;
use App\Actions\Stripe\RefundSubscription;
use App\Actions\Stripe\ResumeSubscription;
use App\Actions\Stripe\UpdateSubscriptionQuantity;
use App\Actions\User\DeleteUserAccount;
use App\Models\Team;
use Carbon\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Component;
use Stripe\StripeClient;

class Actions extends Component
{
    public $server_limits = 0;

    public int $quantity = UpdateSubscriptionQuantity::MIN_SERVER_LIMIT;

    public int $minServerLimit = UpdateSubscriptionQuantity::MIN_SERVER_LIMIT;

    public int $maxServerLimit = UpdateSubscriptionQuantity::MAX_SERVER_LIMIT;

    public ?array $pricePreview = null;

    public bool $isRefundEligible = false;

    public int $refundDaysRemaining = 0;

    public bool $refundCheckLoading = true;

    public bool $refundAlreadyUsed = false;

    public bool $refundLatestPayment = false;

    public bool $deleteAccount = false;

    public bool $canDeleteAccount = false;

    public string $billingInterval = 'monthly';

    public ?string $nextBillingDate = null;

    public function mount(): void
    {
        $this->server_limits = Team::serverLimit();
        $this->quantity = (int) $this->server_limits;
        $this->billingInterval = currentTeam()->subscription?->billingInterval() ?? 'monthly';
        $this->canDeleteAccount = app(DeleteUserAccount::class)->blockers(Auth::user(), removeTeamResources: true) === [];
    }

    public function loadPricePreview(int $quantity): void
    {
        $this->quantity = $quantity;
        $result = (new UpdateSubscriptionQuantity)->fetchPricePreview(currentTeam(), $quantity);
        $this->pricePreview = $result['success'] ? $result['preview'] : null;
    }

    // Password validation is intentionally skipped for quantity updates.
    // Unlike refunds/cancellations, changing the server limit is a
    // non-destructive, reversible billing adjustment (prorated by Stripe).
    public function updateQuantity(string $password = ''): bool
    {
        if ($this->quantity < UpdateSubscriptionQuantity::MIN_SERVER_LIMIT) {
            $this->dispatch('error', 'Minimum server limit is '.UpdateSubscriptionQuantity::MIN_SERVER_LIMIT.'.');
            $this->quantity = UpdateSubscriptionQuantity::MIN_SERVER_LIMIT;

            return true;
        }

        if ($this->quantity === (int) $this->server_limits) {
            return true;
        }

        $previousServerLimit = (int) $this->server_limits;
        $result = (new UpdateSubscriptionQuantity)->execute(currentTeam(), $this->quantity);

        if ($result['success']) {
            $this->auditSubscriptionChange('quantity_updated', [
                'from_server_limit' => $previousServerLimit,
                'to_server_limit' => $this->quantity,
            ]);
            $this->server_limits = $this->quantity;
            $this->pricePreview = null;
            $this->dispatch('success', 'Server limit updated to '.$this->quantity.'.');

            return true;
        }

        $this->dispatch('error', $result['error'] ?? 'Failed to update server limit.');
        $this->quantity = (int) $this->server_limits;

        return true;
    }

    public function loadRefundEligibility(): void
    {
        $this->checkRefundEligibility();
        $this->refundCheckLoading = false;
    }

    public function stripeCustomerPortal(): void
    {
        $session = getStripeCustomerPortalSession(currentTeam());
        redirect($session->url);
    }

    public function refundSubscription(string $password): bool|string
    {
        if (! shouldSkipPasswordConfirmation() && ! Hash::check($password, auth()->user()->password)) {
            return 'Invalid password.';
        }

        $result = app(RefundSubscription::class)->execute(currentTeam());

        if ($result['success']) {
            $this->auditSubscriptionChange('refunded');
            $this->dispatch('success', 'Subscription refunded successfully.');
            $this->redirect(route('subscription.index'), navigate: true);

            return true;
        }

        $this->dispatch('error', 'Something went wrong with the refund. Please <a href="'.config('constants.urls.contact').'" target="_blank" class="underline">contact us</a>.');

        return true;
    }

    public function cancelImmediately(string $password, array $selectedActions = []): bool|string
    {
        if (! shouldSkipPasswordConfirmation() && ! Hash::check($password, auth()->user()->password)) {
            return 'Invalid password.';
        }

        $deleteAccount = in_array('deleteAccount', $selectedActions, true);
        if ($deleteAccount && ($blockers = app(DeleteUserAccount::class)->blockers(Auth::user(), removeTeamResources: true))) {
            return implode(' ', $blockers);
        }

        $refund = in_array('refundLatestPayment', $selectedActions, true);
        if (! $this->cancelSubscriptionNow($refund)) {
            $this->dispatch('error', 'Something went wrong with the '.($refund ? 'refund' : 'cancellation').'. Please <a href="'.config('constants.urls.contact').'" target="_blank" class="underline">contact us</a>.');

            return true;
        }

        if ($deleteAccount) {
            return $this->deleteAccountAfterCancellation();
        }

        $this->dispatch('success', $refund ? 'Subscription refunded and cancelled successfully.' : 'Subscription cancelled successfully.');
        $this->redirect(route('subscription.index'), navigate: true);

        return true;
    }

    public function cancelAtPeriodEnd(string $password): bool|string
    {
        if (! shouldSkipPasswordConfirmation() && ! Hash::check($password, auth()->user()->password)) {
            return 'Invalid password.';
        }

        $result = (new CancelSubscriptionAtPeriodEnd)->execute(currentTeam());

        if ($result['success']) {
            $this->auditSubscriptionChange('cancellation_scheduled', ['cancel_at_period_end' => true]);
            $this->dispatch('success', 'Subscription will be cancelled at the end of the billing period.');

            return true;
        }

        $this->dispatch('error', 'Something went wrong with the cancellation. Please <a href="'.config('constants.urls.contact').'" target="_blank" class="underline">contact us</a>.');

        return true;
    }

    public function resumeSubscription(): bool
    {
        $result = (new ResumeSubscription)->execute(currentTeam());

        if ($result['success']) {
            $this->auditSubscriptionChange('resumed');
            $this->dispatch('success', 'Subscription resumed successfully.');

            return true;
        }

        $this->dispatch('error', 'Something went wrong resuming the subscription. Please <a href="'.config('constants.urls.contact').'" target="_blank" class="underline">contact us</a>.');

        return true;
    }

    private function cancelSubscriptionNow(bool $refund): bool
    {
        $team = currentTeam();

        if ($refund) {
            // Eligibility is re-validated server-side inside RefundSubscription::execute()
            $result = app(RefundSubscription::class)->execute($team);

            if ($result['success']) {
                $this->auditSubscriptionChange('refunded');
            }

            return $result['success'];
        }

        $subscription = $team->subscription;

        if (! $subscription?->stripe_subscription_id) {
            return false;
        }

        // subscriptionEnded() clears the ID on this model, so keep it for the log.
        $subscriptionId = $subscription->stripe_subscription_id;

        try {
            $stripe = app(StripeClient::class);
            $stripe->subscriptions->cancel($subscriptionId);

            $subscription->update([
                'stripe_cancel_at_period_end' => false,
                'stripe_invoice_paid' => false,
                'stripe_trial_already_ended' => false,
                'stripe_past_due' => false,
                'stripe_feedback' => 'Cancelled immediately by user',
                'stripe_comment' => 'Subscription cancelled immediately by user at '.now()->toDateTimeString(),
            ]);

            $team->subscriptionEnded();

            $this->auditSubscriptionChange('cancelled', ['cancel_at_period_end' => false]);

            \Log::info("Subscription {$subscriptionId} cancelled immediately for team {$team->name}");

            return true;
        } catch (\Exception $e) {
            \Log::error("Immediate cancellation error for team {$team->id}: ".$e->getMessage());

            return false;
        }
    }

    private function deleteAccountAfterCancellation(): bool
    {
        try {
            app(DeleteUserAccount::class)->handle(Auth::user(), removeTeamResources: true);
        } catch (\Throwable $e) {
            report($e);
            $this->dispatch('error', 'Your subscription was cancelled, but your account could not be deleted. Delete it from your profile page or <a href="'.config('constants.urls.contact').'" target="_blank" class="underline">contact us</a>.');
            $this->redirect(route('subscription.index'), navigate: true);

            return true;
        }

        auditLog('ui.user.account_deleted', ['resource' => 'user']);

        Auth::guard('web')->logout();
        session()->invalidate();
        session()->regenerateToken();

        $this->redirect(route('login'));

        return true;
    }

    /**
     * Record a user-triggered subscription change for the current team.
     *
     * @param  array<string, mixed>  $context
     */
    private function auditSubscriptionChange(string $action, array $context = []): void
    {
        $team = currentTeam();

        auditLog("ui.subscription.{$action}", [
            'team_id' => $team?->id,
            'resource' => 'subscription',
            'subscription_id' => $team?->subscription?->id,
            'team_name' => $team?->name,
            ...$context,
        ]);
    }

    private function checkRefundEligibility(): void
    {
        if (! isCloud() || ! currentTeam()->subscription?->stripe_subscription_id) {
            return;
        }

        try {
            $this->refundAlreadyUsed = currentTeam()->subscription?->stripe_refunded_at !== null;
            $result = (new RefundSubscription)->checkEligibility(currentTeam());
            $this->isRefundEligible = $result['eligible'];
            $this->refundDaysRemaining = $result['days_remaining'];

            if ($result['current_period_end']) {
                $this->nextBillingDate = Carbon::createFromTimestamp($result['current_period_end'])->format('M j, Y');
            }
        } catch (\Exception $e) {
            \Log::warning('Refund eligibility check failed: '.$e->getMessage());
        }
    }
}
