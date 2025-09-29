<?php

namespace App\Console\Commands\Cloud;

use App\Models\Team;
use Illuminate\Console\Command;

class CloudFixSubscription extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cloud:fix-subscription
                            {--fix-canceled-subs : Fix canceled subscriptions in database}
                            {--verify-all : Verify all active subscriptions against Stripe}
                            {--fix-verified : Fix discrepancies found during verification}
                            {--dry-run : Show what would be fixed without making changes}
                            {--one : Only fix the first found subscription}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fix Cloud subscriptions';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $stripe = new \Stripe\StripeClient(config('subscription.stripe_api_key'));

        if ($this->option('verify-all')) {
            return $this->verifyAllActiveSubscriptions($stripe);
        }

        if ($this->option('fix-canceled-subs') || $this->option('dry-run')) {
            return $this->fixCanceledSubscriptions($stripe);
        }

        $activeSubscribers = Team::whereRelation('subscription', 'stripe_invoice_paid', true)->get();

        $out = fopen('php://output', 'w');
        // CSV header
        fputcsv($out, [
            'team_id',
            'invoice_status',
            'stripe_customer_url',
            'stripe_subscription_id',
            'subscription_status',
            'subscription_url',
            'note',
        ]);

        foreach ($activeSubscribers as $team) {
            $stripeSubscriptionId = $team->subscription->stripe_subscription_id;
            $stripeInvoicePaid = $team->subscription->stripe_invoice_paid;
            $stripeCustomerId = $team->subscription->stripe_customer_id;

            if (! $stripeSubscriptionId && str($stripeInvoicePaid)->lower() != 'past_due') {
                fputcsv($out, [
                    $team->id,
                    $stripeInvoicePaid,
                    $stripeCustomerId ? "https://dashboard.stripe.com/customers/{$stripeCustomerId}" : null,
                    null,
                    null,
                    null,
                    'Missing subscription ID while invoice not past_due',
                ]);

                continue;
            }

            if (! $stripeSubscriptionId) {
                // No subscription ID and invoice is past_due, still record for visibility
                fputcsv($out, [
                    $team->id,
                    $stripeInvoicePaid,
                    $stripeCustomerId ? "https://dashboard.stripe.com/customers/{$stripeCustomerId}" : null,
                    null,
                    null,
                    null,
                    'Missing subscription ID',
                ]);

                continue;
            }

            $subscription = $stripe->subscriptions->retrieve($stripeSubscriptionId);
            if ($subscription->status === 'active') {
                continue;
            }

            fputcsv($out, [
                $team->id,
                $stripeInvoicePaid,
                $stripeCustomerId ? "https://dashboard.stripe.com/customers/{$stripeCustomerId}" : null,
                $stripeSubscriptionId,
                $subscription->status,
                "https://dashboard.stripe.com/subscriptions/{$stripeSubscriptionId}",
                'Subscription not active',
            ]);
        }

        fclose($out);
    }

    /**
     * Fix canceled subscriptions in the database
     */
    private function fixCanceledSubscriptions(\Stripe\StripeClient $stripe)
    {
        $isDryRun = $this->option('dry-run');
        $checkOne = $this->option('one');

        if ($isDryRun) {
            $this->info('DRY RUN MODE - No changes will be made');
            if ($checkOne) {
                $this->info('Checking only the first canceled subscription...');
            } else {
                $this->info('Checking for canceled subscriptions...');
            }
        } else {
            if ($checkOne) {
                $this->info('Checking and fixing only the first canceled subscription...');
            } else {
                $this->info('Checking and fixing canceled subscriptions...');
            }
        }

        $teamsWithSubscriptions = Team::whereRelation('subscription', 'stripe_invoice_paid', true)->get();
        $toFixCount = 0;
        $fixedCount = 0;
        $errors = [];
        $canceledSubscriptions = [];

        foreach ($teamsWithSubscriptions as $team) {
            $subscription = $team->subscription;

            if (! $subscription->stripe_subscription_id) {
                continue;
            }

            try {
                $stripeSubscription = $stripe->subscriptions->retrieve(
                    $subscription->stripe_subscription_id
                );

                if ($stripeSubscription->status === 'canceled') {
                    $toFixCount++;

                    // Get team members' emails
                    $memberEmails = $team->members->pluck('email')->toArray();

                    $canceledSubscriptions[] = [
                        'team_id' => $team->id,
                        'team_name' => $team->name,
                        'customer_id' => $subscription->stripe_customer_id,
                        'subscription_id' => $subscription->stripe_subscription_id,
                        'status' => 'canceled',
                        'member_emails' => $memberEmails,
                        'subscription_model' => $subscription->toArray(),
                    ];

                    if ($isDryRun) {
                        $this->warn('Would fix canceled subscription:');
                        $this->line("  Team ID: {$team->id}");
                        $this->line("  Team Name: {$team->name}");
                        $this->line('  Team Members: '.implode(', ', $memberEmails));
                        $this->line("  Customer URL: https://dashboard.stripe.com/customers/{$subscription->stripe_customer_id}");
                        $this->line("  Subscription URL: https://dashboard.stripe.com/subscriptions/{$subscription->stripe_subscription_id}");
                        $this->line('  Current Subscription Data:');
                        foreach ($subscription->getAttributes() as $key => $value) {
                            if (is_null($value)) {
                                $this->line("    - {$key}: null");
                            } elseif (is_bool($value)) {
                                $this->line("    - {$key}: ".($value ? 'true' : 'false'));
                            } else {
                                $this->line("    - {$key}: {$value}");
                            }
                        }
                        $this->newLine();
                    } else {
                        $this->warn("Found canceled subscription for Team ID: {$team->id}");

                        // Send internal notification with all details before fixing
                        $notificationMessage = "Fixing canceled subscription:\n";
                        $notificationMessage .= "Team ID: {$team->id}\n";
                        $notificationMessage .= "Team Name: {$team->name}\n";
                        $notificationMessage .= 'Team Members: '.implode(', ', $memberEmails)."\n";
                        $notificationMessage .= "Customer URL: https://dashboard.stripe.com/customers/{$subscription->stripe_customer_id}\n";
                        $notificationMessage .= "Subscription URL: https://dashboard.stripe.com/subscriptions/{$subscription->stripe_subscription_id}\n";
                        $notificationMessage .= "Subscription Data:\n";
                        foreach ($subscription->getAttributes() as $key => $value) {
                            if (is_null($value)) {
                                $notificationMessage .= "  - {$key}: null\n";
                            } elseif (is_bool($value)) {
                                $notificationMessage .= "  - {$key}: ".($value ? 'true' : 'false')."\n";
                            } else {
                                $notificationMessage .= "  - {$key}: {$value}\n";
                            }
                        }
                        send_internal_notification($notificationMessage);

                        // Apply the same logic as customer.subscription.deleted webhook
                        $team->subscriptionEnded();

                        $fixedCount++;
                        $this->info("  ✓ Fixed subscription for Team ID: {$team->id}");
                        $this->line('    Team Members: '.implode(', ', $memberEmails));
                        $this->line("    Customer URL: https://dashboard.stripe.com/customers/{$subscription->stripe_customer_id}");
                        $this->line("    Subscription URL: https://dashboard.stripe.com/subscriptions/{$subscription->stripe_subscription_id}");
                    }

                    // Break if --one flag is set
                    if ($checkOne) {
                        break;
                    }
                }
            } catch (\Stripe\Exception\InvalidRequestException $e) {
                if ($e->getStripeCode() === 'resource_missing') {
                    $toFixCount++;

                    // Get team members' emails
                    $memberEmails = $team->members->pluck('email')->toArray();

                    $canceledSubscriptions[] = [
                        'team_id' => $team->id,
                        'team_name' => $team->name,
                        'customer_id' => $subscription->stripe_customer_id,
                        'subscription_id' => $subscription->stripe_subscription_id,
                        'status' => 'missing',
                        'member_emails' => $memberEmails,
                        'subscription_model' => $subscription->toArray(),
                    ];

                    if ($isDryRun) {
                        $this->error('Would fix missing subscription (not found in Stripe):');
                        $this->line("  Team ID: {$team->id}");
                        $this->line("  Team Name: {$team->name}");
                        $this->line('  Team Members: '.implode(', ', $memberEmails));
                        $this->line("  Customer URL: https://dashboard.stripe.com/customers/{$subscription->stripe_customer_id}");
                        $this->line("  Subscription ID (missing): {$subscription->stripe_subscription_id}");
                        $this->line('  Current Subscription Data:');
                        foreach ($subscription->getAttributes() as $key => $value) {
                            if (is_null($value)) {
                                $this->line("    - {$key}: null");
                            } elseif (is_bool($value)) {
                                $this->line("    - {$key}: ".($value ? 'true' : 'false'));
                            } else {
                                $this->line("    - {$key}: {$value}");
                            }
                        }
                        $this->newLine();
                    } else {
                        $this->error("Subscription not found in Stripe for Team ID: {$team->id}");

                        // Send internal notification with all details before fixing
                        $notificationMessage = "Fixing missing subscription (not found in Stripe):\n";
                        $notificationMessage .= "Team ID: {$team->id}\n";
                        $notificationMessage .= "Team Name: {$team->name}\n";
                        $notificationMessage .= 'Team Members: '.implode(', ', $memberEmails)."\n";
                        $notificationMessage .= "Customer URL: https://dashboard.stripe.com/customers/{$subscription->stripe_customer_id}\n";
                        $notificationMessage .= "Subscription ID (missing): {$subscription->stripe_subscription_id}\n";
                        $notificationMessage .= "Subscription Data:\n";
                        foreach ($subscription->getAttributes() as $key => $value) {
                            if (is_null($value)) {
                                $notificationMessage .= "  - {$key}: null\n";
                            } elseif (is_bool($value)) {
                                $notificationMessage .= "  - {$key}: ".($value ? 'true' : 'false')."\n";
                            } else {
                                $notificationMessage .= "  - {$key}: {$value}\n";
                            }
                        }
                        send_internal_notification($notificationMessage);

                        // Apply the same logic as customer.subscription.deleted webhook
                        $team->subscriptionEnded();

                        $fixedCount++;
                        $this->info("  ✓ Fixed missing subscription for Team ID: {$team->id}");
                        $this->line('    Team Members: '.implode(', ', $memberEmails));
                        $this->line("    Customer URL: https://dashboard.stripe.com/customers/{$subscription->stripe_customer_id}");
                    }

                    // Break if --one flag is set
                    if ($checkOne) {
                        break;
                    }
                } else {
                    $errors[] = "Team ID {$team->id}: ".$e->getMessage();
                }
            } catch (\Exception $e) {
                $errors[] = "Team ID {$team->id}: ".$e->getMessage();
            }
        }

        $this->newLine();
        $this->info('Summary:');

        if ($isDryRun) {
            $this->info("  - Found {$toFixCount} canceled/missing subscriptions that would be fixed");

            if ($toFixCount > 0) {
                $this->newLine();
                $this->comment('Run with --fix-canceled-subs to apply these changes');
            }
        } else {
            $this->info("  - Fixed {$fixedCount} canceled/missing subscriptions");
        }

        if (! empty($errors)) {
            $this->newLine();
            $this->error('Errors encountered:');
            foreach ($errors as $error) {
                $this->error("  - {$error}");
            }
        }

        return 0;
    }

    /**
     * Verify all active subscriptions against Stripe API
     */
    private function verifyAllActiveSubscriptions(\Stripe\StripeClient $stripe)
    {
        $isDryRun = $this->option('dry-run');
        $shouldFix = $this->option('fix-verified');

        $this->info('Verifying all active subscriptions against Stripe...');
        if ($isDryRun) {
            $this->info('DRY RUN MODE - No changes will be made');
        }
        if ($shouldFix && ! $isDryRun) {
            $this->warn('FIX MODE - Discrepancies will be corrected');
        }

        // Get all teams with active subscriptions
        $teamsWithActiveSubscriptions = Team::whereRelation('subscription', 'stripe_invoice_paid', true)->get();
        $totalCount = $teamsWithActiveSubscriptions->count();

        $this->info("Found {$totalCount} teams with active subscriptions in database");
        $this->newLine();

        $out = fopen('php://output', 'w');

        // CSV header
        fputcsv($out, [
            'team_id',
            'team_name',
            'customer_id',
            'subscription_id',
            'db_status',
            'stripe_status',
            'action',
            'member_emails',
            'customer_url',
            'subscription_url',
        ]);

        $stats = [
            'total' => $totalCount,
            'valid_active' => 0,
            'valid_past_due' => 0,
            'canceled' => 0,
            'missing' => 0,
            'invalid' => 0,
            'fixed' => 0,
            'errors' => 0,
        ];

        $processedCount = 0;

        foreach ($teamsWithActiveSubscriptions as $team) {
            $subscription = $team->subscription;
            $memberEmails = $team->members->pluck('email')->toArray();

            // Database state
            $dbStatus = 'active';
            if ($subscription->stripe_past_due) {
                $dbStatus = 'past_due';
            }

            $stripeStatus = null;
            $action = 'none';

            if (! $subscription->stripe_subscription_id) {
                $this->line("Team {$team->id}: Missing subscription ID, searching in Stripe...");

                $foundResult = null;
                $searchMethod = null;

                // Search by customer ID
                if ($subscription->stripe_customer_id) {
                    $this->line("  → Searching by customer ID: {$subscription->stripe_customer_id}");
                    $foundResult = $this->searchSubscriptionsByCustomer($stripe, $subscription->stripe_customer_id);
                    if ($foundResult) {
                        $searchMethod = $foundResult['method'];
                    }
                } else {
                    $this->line('  → No customer ID available');
                }

                // Search by emails if not found
                if (! $foundResult && count($memberEmails) > 0) {
                    $foundResult = $this->searchSubscriptionsByEmails($stripe, $memberEmails);
                    if ($foundResult) {
                        $searchMethod = $foundResult['method'];

                        // Update customer ID if different
                        if (isset($foundResult['customer_id']) && $subscription->stripe_customer_id !== $foundResult['customer_id']) {
                            if ($isDryRun) {
                                $this->warn("  ⚠ Would update customer ID from {$subscription->stripe_customer_id} to {$foundResult['customer_id']}");
                            } elseif ($shouldFix) {
                                $subscription->update(['stripe_customer_id' => $foundResult['customer_id']]);
                                $this->info("  ✓ Updated customer ID to {$foundResult['customer_id']}");
                            }
                        }
                    }
                }

                if ($foundResult && isset($foundResult['subscription'])) {
                    // Check if it's an active/past_due subscription
                    if (in_array($foundResult['status'], ['active', 'past_due'])) {
                        // Found an active subscription, handle update
                        $result = $this->handleFoundSubscription(
                            $team,
                            $subscription,
                            $foundResult['subscription'],
                            $searchMethod,
                            $isDryRun,
                            $shouldFix,
                            $stats
                        );

                        fputcsv($out, [
                            $team->id,
                            $team->name,
                            $subscription->stripe_customer_id,
                            $result['id'],
                            $dbStatus,
                            $result['status'],
                            $result['action'],
                            implode(', ', $memberEmails),
                            $subscription->stripe_customer_id ? "https://dashboard.stripe.com/customers/{$subscription->stripe_customer_id}" : 'N/A',
                            $result['url'],
                        ]);
                    } else {
                        // Found subscription but it's canceled/expired - needs to be deactivated
                        $this->warn("  → Found {$foundResult['status']} subscription {$foundResult['subscription']->id} - needs deactivation");

                        $result = $this->handleMissingSubscription($team, $subscription, $foundResult['status'], $isDryRun, $shouldFix, $stats);

                        fputcsv($out, [
                            $team->id,
                            $team->name,
                            $subscription->stripe_customer_id,
                            $foundResult['subscription']->id,
                            $dbStatus,
                            $foundResult['status'],
                            'needs_fix',
                            implode(', ', $memberEmails),
                            $subscription->stripe_customer_id ? "https://dashboard.stripe.com/customers/{$subscription->stripe_customer_id}" : 'N/A',
                            "https://dashboard.stripe.com/subscriptions/{$foundResult['subscription']->id}",
                        ]);
                    }
                } else {
                    // No subscription found at all
                    $this->line('  → No subscription found');

                    $stripeStatus = 'not_found';
                    $result = $this->handleMissingSubscription($team, $subscription, $stripeStatus, $isDryRun, $shouldFix, $stats);

                    fputcsv($out, [
                        $team->id,
                        $team->name,
                        $subscription->stripe_customer_id,
                        'N/A',
                        $dbStatus,
                        $result['status'],
                        $result['action'],
                        implode(', ', $memberEmails),
                        $subscription->stripe_customer_id ? "https://dashboard.stripe.com/customers/{$subscription->stripe_customer_id}" : 'N/A',
                        'N/A',
                    ]);
                }
            } else {
                // First validate the subscription ID format
                if (! str_starts_with($subscription->stripe_subscription_id, 'sub_')) {
                    $this->warn("  ⚠ Invalid subscription ID format (doesn't start with 'sub_')");
                }

                try {
                    $stripeSubscription = $stripe->subscriptions->retrieve(
                        $subscription->stripe_subscription_id
                    );

                    $stripeStatus = $stripeSubscription->status;

                    // Determine if action is needed
                    switch ($stripeStatus) {
                        case 'active':
                            $stats['valid_active']++;
                            $action = 'valid';
                            break;

                        case 'past_due':
                            $stats['valid_past_due']++;
                            $action = 'valid';
                            // Ensure past_due flag is set
                            if (! $subscription->stripe_past_due) {
                                if ($isDryRun) {
                                    $this->info("Would set stripe_past_due=true for Team {$team->id}");
                                } elseif ($shouldFix) {
                                    $subscription->update(['stripe_past_due' => true]);
                                }
                            }
                            break;

                        case 'canceled':
                        case 'incomplete_expired':
                        case 'unpaid':
                        case 'incomplete':
                            $stats['canceled']++;
                            $action = 'needs_fix';

                            // Only output problematic subscriptions
                            fputcsv($out, [
                                $team->id,
                                $team->name,
                                $subscription->stripe_customer_id,
                                $subscription->stripe_subscription_id,
                                $dbStatus,
                                $stripeStatus,
                                $action,
                                implode(', ', $memberEmails),
                                "https://dashboard.stripe.com/customers/{$subscription->stripe_customer_id}",
                                "https://dashboard.stripe.com/subscriptions/{$subscription->stripe_subscription_id}",
                            ]);

                            if ($isDryRun) {
                                $this->info("Would deactivate subscription for Team {$team->id} - status: {$stripeStatus}");
                            } elseif ($shouldFix) {
                                $this->fixSubscription($team, $subscription, $stripeStatus);
                                $stats['fixed']++;
                            }
                            break;

                        default:
                            $stats['invalid']++;
                            $action = 'unknown';

                            // Only output problematic subscriptions
                            fputcsv($out, [
                                $team->id,
                                $team->name,
                                $subscription->stripe_customer_id,
                                $subscription->stripe_subscription_id,
                                $dbStatus,
                                $stripeStatus,
                                $action,
                                implode(', ', $memberEmails),
                                "https://dashboard.stripe.com/customers/{$subscription->stripe_customer_id}",
                                "https://dashboard.stripe.com/subscriptions/{$subscription->stripe_subscription_id}",
                            ]);
                            break;
                    }

                } catch (\Stripe\Exception\InvalidRequestException $e) {
                    $this->error('  → Error: '.$e->getMessage());

                    if ($e->getStripeCode() === 'resource_missing' || $e->getHttpStatus() === 404) {
                        // Subscription doesn't exist, try to find by customer ID
                        $this->warn("  → Subscription not found, checking customer's subscriptions...");

                        $foundResult = null;
                        if ($subscription->stripe_customer_id) {
                            $foundResult = $this->searchSubscriptionsByCustomer($stripe, $subscription->stripe_customer_id);
                        }

                        if ($foundResult && isset($foundResult['subscription']) && in_array($foundResult['status'], ['active', 'past_due'])) {
                            // Found an active subscription with different ID
                            $this->warn("  → ID mismatch! DB: {$subscription->stripe_subscription_id}, Stripe: {$foundResult['subscription']->id}");

                            fputcsv($out, [
                                $team->id,
                                $team->name,
                                $subscription->stripe_customer_id,
                                "WRONG ID: {$subscription->stripe_subscription_id} → {$foundResult['subscription']->id}",
                                $dbStatus,
                                $foundResult['status'],
                                'id_mismatch',
                                implode(', ', $memberEmails),
                                "https://dashboard.stripe.com/customers/{$subscription->stripe_customer_id}",
                                "https://dashboard.stripe.com/subscriptions/{$foundResult['subscription']->id}",
                            ]);

                            if ($isDryRun) {
                                $this->warn("  → Would update subscription ID to {$foundResult['subscription']->id}");
                            } elseif ($shouldFix) {
                                $subscription->update([
                                    'stripe_subscription_id' => $foundResult['subscription']->id,
                                    'stripe_invoice_paid' => true,
                                    'stripe_past_due' => $foundResult['status'] === 'past_due',
                                ]);
                                $stats['fixed']++;
                                $this->info('  → Updated subscription ID');
                            }

                            $stats[$foundResult['status'] === 'active' ? 'valid_active' : 'valid_past_due']++;
                        } else {
                            // No active subscription found
                            $stripeStatus = $foundResult ? $foundResult['status'] : 'not_found';
                            $result = $this->handleMissingSubscription($team, $subscription, $stripeStatus, $isDryRun, $shouldFix, $stats);

                            fputcsv($out, [
                                $team->id,
                                $team->name,
                                $subscription->stripe_customer_id,
                                $subscription->stripe_subscription_id,
                                $dbStatus,
                                $result['status'],
                                $result['action'],
                                implode(', ', $memberEmails),
                                $subscription->stripe_customer_id ? "https://dashboard.stripe.com/customers/{$subscription->stripe_customer_id}" : 'N/A',
                                $foundResult && isset($foundResult['subscription']) ? "https://dashboard.stripe.com/subscriptions/{$foundResult['subscription']->id}" : 'N/A',
                            ]);
                        }
                    } else {
                        // Other API error
                        $stats['errors']++;
                        $this->error('  → API Error - not marking as deleted');

                        fputcsv($out, [
                            $team->id,
                            $team->name,
                            $subscription->stripe_customer_id,
                            $subscription->stripe_subscription_id,
                            $dbStatus,
                            'error: '.$e->getStripeCode(),
                            'error',
                            implode(', ', $memberEmails),
                            $subscription->stripe_customer_id ? "https://dashboard.stripe.com/customers/{$subscription->stripe_customer_id}" : 'N/A',
                            $subscription->stripe_subscription_id ? "https://dashboard.stripe.com/subscriptions/{$subscription->stripe_subscription_id}" : 'N/A',
                        ]);
                    }
                } catch (\Exception $e) {
                    $this->error('  → Unexpected error: '.$e->getMessage());
                    $stats['errors']++;

                    fputcsv($out, [
                        $team->id,
                        $team->name,
                        $subscription->stripe_customer_id,
                        $subscription->stripe_subscription_id,
                        $dbStatus,
                        'error',
                        'error',
                        implode(', ', $memberEmails),
                        $subscription->stripe_customer_id ? "https://dashboard.stripe.com/customers/{$subscription->stripe_customer_id}" : 'N/A',
                        $subscription->stripe_subscription_id ? "https://dashboard.stripe.com/subscriptions/{$subscription->stripe_subscription_id}" : 'N/A',
                    ]);
                }
            }

            $processedCount++;
            if ($processedCount % 100 === 0) {
                $this->info("Processed {$processedCount}/{$totalCount} subscriptions...");
            }
        }

        fclose($out);

        // Print summary
        $this->newLine(2);
        $this->info('=== Verification Summary ===');
        $this->info("Total subscriptions checked: {$stats['total']}");
        $this->newLine();

        $this->info('Valid subscriptions in Stripe:');
        $this->line("  - Active: {$stats['valid_active']}");
        $this->line("  - Past Due: {$stats['valid_past_due']}");
        $validTotal = $stats['valid_active'] + $stats['valid_past_due'];
        $this->info("  Total valid: {$validTotal}");

        $this->newLine();
        $this->warn('Invalid subscriptions:');
        $this->line("  - Canceled/Expired: {$stats['canceled']}");
        $this->line("  - Missing/Not Found: {$stats['missing']}");
        $this->line("  - Unknown status: {$stats['invalid']}");
        $invalidTotal = $stats['canceled'] + $stats['missing'] + $stats['invalid'];
        $this->warn("  Total invalid: {$invalidTotal}");

        if ($stats['errors'] > 0) {
            $this->newLine();
            $this->error("Errors encountered: {$stats['errors']}");
        }

        if ($shouldFix && ! $isDryRun) {
            $this->newLine();
            $this->info("Fixed subscriptions: {$stats['fixed']}");
        } elseif ($invalidTotal > 0 && ! $shouldFix) {
            $this->newLine();
            $this->comment('Run with --fix-verified to fix the discrepancies');
        }

        return 0;
    }

    /**
     * Fix a subscription based on its status
     */
    private function fixSubscription($team, $subscription, $status)
    {
        $message = "Fixing subscription for Team ID: {$team->id} (Status: {$status})\n";
        $message .= "Team Name: {$team->name}\n";
        $message .= "Customer ID: {$subscription->stripe_customer_id}\n";
        $message .= "Subscription ID: {$subscription->stripe_subscription_id}\n";

        send_internal_notification($message);

        // Call the team's subscription ended method which properly cleans up
        $team->subscriptionEnded();
    }

    /**
     * Search for subscriptions by customer ID
     */
    private function searchSubscriptionsByCustomer(\Stripe\StripeClient $stripe, $customerId, $requireActive = false)
    {
        try {
            $subscriptions = $stripe->subscriptions->all([
                'customer' => $customerId,
                'limit' => 10,
                'status' => 'all',
            ]);

            $this->line('  → Found '.count($subscriptions->data).' subscription(s) for customer');

            // Look for active/past_due first
            foreach ($subscriptions->data as $sub) {
                $this->line("    - Subscription {$sub->id}: status={$sub->status}");
                if (in_array($sub->status, ['active', 'past_due'])) {
                    $this->info("    ✓ Found active/past_due subscription: {$sub->id}");

                    return ['subscription' => $sub, 'status' => $sub->status, 'method' => 'customer_id'];
                }
            }

            // If not requiring active and there are subscriptions, return first one
            if (! $requireActive && count($subscriptions->data) > 0) {
                $sub = $subscriptions->data[0];
                $this->warn("    ⚠ Only found {$sub->status} subscription: {$sub->id}");

                return ['subscription' => $sub, 'status' => $sub->status, 'method' => 'customer_id_first'];
            }

            return null;
        } catch (\Exception $e) {
            $this->error('  → Error searching by customer ID: '.$e->getMessage());

            return null;
        }
    }

    /**
     * Search for subscriptions by team member emails
     */
    private function searchSubscriptionsByEmails(\Stripe\StripeClient $stripe, $emails)
    {
        $this->line('  → Searching by team member emails...');

        foreach ($emails as $email) {
            $this->line("    → Checking email: {$email}");

            try {
                $customers = $stripe->customers->all([
                    'email' => $email,
                    'limit' => 5,
                ]);

                if (count($customers->data) === 0) {
                    $this->line('      - No customers found');

                    continue;
                }

                $this->line('      - Found '.count($customers->data).' customer(s)');

                foreach ($customers->data as $customer) {
                    $this->line("      - Checking customer {$customer->id}");

                    $result = $this->searchSubscriptionsByCustomer($stripe, $customer->id, true);
                    if ($result) {
                        $result['method'] = "email:{$email}";
                        $result['customer_id'] = $customer->id;

                        return $result;
                    }
                }
            } catch (\Exception $e) {
                $this->error("      - Error searching for email {$email}: ".$e->getMessage());
            }
        }

        return null;
    }

    /**
     * Handle found subscription update (only for active/past_due subscriptions)
     */
    private function handleFoundSubscription($team, $subscription, $foundSub, $searchMethod, $isDryRun, $shouldFix, &$stats)
    {
        $stripeStatus = $foundSub->status;
        $this->info("  ✓ FOUND active/past_due subscription {$foundSub->id} (status: {$stripeStatus})");

        // Only update if it's active or past_due
        if (! in_array($stripeStatus, ['active', 'past_due'])) {
            $this->error("  ERROR: handleFoundSubscription called with {$stripeStatus} subscription!");

            return [
                'id' => $foundSub->id,
                'status' => $stripeStatus,
                'action' => 'error',
                'url' => "https://dashboard.stripe.com/subscriptions/{$foundSub->id}",
            ];
        }

        if ($isDryRun) {
            $this->warn("  → Would update subscription ID to {$foundSub->id} (status: {$stripeStatus})");
        } elseif ($shouldFix) {
            $subscription->update([
                'stripe_subscription_id' => $foundSub->id,
                'stripe_invoice_paid' => true,
                'stripe_past_due' => $stripeStatus === 'past_due',
            ]);
            $stats['fixed']++;
            $this->info("  → Updated subscription ID to {$foundSub->id}");
        }

        // Update stats
        $stats[$stripeStatus === 'active' ? 'valid_active' : 'valid_past_due']++;

        return [
            'id' => "FOUND: {$foundSub->id}",
            'status' => $stripeStatus,
            'action' => "will_update (via {$searchMethod})",
            'url' => "https://dashboard.stripe.com/subscriptions/{$foundSub->id}",
        ];
    }

    /**
     * Handle missing subscription
     */
    private function handleMissingSubscription($team, $subscription, $status, $isDryRun, $shouldFix, &$stats)
    {
        $stats['missing']++;

        if ($isDryRun) {
            $statusMsg = $status !== 'not_found' ? "status: {$status}" : 'no subscription found in Stripe';
            $this->warn("  → Would deactivate subscription - {$statusMsg}");
        } elseif ($shouldFix) {
            $this->fixSubscription($team, $subscription, $status);
            $stats['fixed']++;
            $this->info('  → Deactivated subscription');
        }

        return [
            'id' => 'N/A',
            'status' => $status,
            'action' => 'needs_fix',
            'url' => 'N/A',
        ];
    }
}
