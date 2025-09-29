<?php

namespace App\Console\Commands\Cloud;

use App\Actions\Stripe\CancelSubscription;
use App\Actions\User\DeleteUserResources;
use App\Actions\User\DeleteUserServers;
use App\Actions\User\DeleteUserTeams;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CloudDeleteUser extends Command
{
    protected $signature = 'cloud:delete-user {email} 
                            {--dry-run : Preview what will be deleted without actually deleting}
                            {--skip-stripe : Skip Stripe subscription cancellation}
                            {--skip-resources : Skip resource deletion}';

    protected $description = 'Delete a user from the cloud instance with phase-by-phase confirmation';

    private bool $isDryRun = false;

    private bool $skipStripe = false;

    private bool $skipResources = false;

    private User $user;

    public function handle()
    {
        if (! isCloud()) {
            $this->error('This command is only available on cloud instances.');

            return 1;
        }

        $email = $this->argument('email');
        $this->isDryRun = $this->option('dry-run');
        $this->skipStripe = $this->option('skip-stripe');
        $this->skipResources = $this->option('skip-resources');

        if ($this->isDryRun) {
            $this->info('🔍 DRY RUN MODE - No data will be deleted');
            $this->newLine();
        }

        try {
            $this->user = User::whereEmail($email)->firstOrFail();
        } catch (\Exception $e) {
            $this->error("User with email '{$email}' not found.");

            return 1;
        }

        // Implement file lock to prevent concurrent deletions of the same user
        $lockKey = "user_deletion_{$this->user->id}";
        $lock = Cache::lock($lockKey, 600); // 10 minute lock

        if (! $lock->get()) {
            $this->error('Another deletion process is already running for this user. Please try again later.');
            $this->logAction("Deletion blocked for user {$email}: Another process is already running");

            return 1;
        }

        try {
            $this->logAction("Starting user deletion process for: {$email}");

            // Phase 1: Show User Overview (outside transaction)
            if (! $this->showUserOverview()) {
                $this->info('User deletion cancelled.');
                $lock->release();

                return 0;
            }

            // If not dry run, wrap everything in a transaction
            if (! $this->isDryRun) {
                try {
                    DB::beginTransaction();

                    // Phase 2: Delete Resources
                    if (! $this->skipResources) {
                        if (! $this->deleteResources()) {
                            DB::rollBack();
                            $this->error('User deletion failed at resource deletion phase. All changes rolled back.');

                            return 1;
                        }
                    }

                    // Phase 3: Delete Servers
                    if (! $this->deleteServers()) {
                        DB::rollBack();
                        $this->error('User deletion failed at server deletion phase. All changes rolled back.');

                        return 1;
                    }

                    // Phase 4: Handle Teams
                    if (! $this->handleTeams()) {
                        DB::rollBack();
                        $this->error('User deletion failed at team handling phase. All changes rolled back.');

                        return 1;
                    }

                    // Phase 5: Cancel Stripe Subscriptions
                    if (! $this->skipStripe && isCloud()) {
                        if (! $this->cancelStripeSubscriptions()) {
                            DB::rollBack();
                            $this->error('User deletion failed at Stripe cancellation phase. All changes rolled back.');

                            return 1;
                        }
                    }

                    // Phase 6: Delete User Profile
                    if (! $this->deleteUserProfile()) {
                        DB::rollBack();
                        $this->error('User deletion failed at final phase. All changes rolled back.');

                        return 1;
                    }

                    // Commit the transaction
                    DB::commit();

                    $this->newLine();
                    $this->info('✅ User deletion completed successfully!');
                    $this->logAction("User deletion completed for: {$email}");

                } catch (\Exception $e) {
                    DB::rollBack();
                    $this->error('An error occurred during user deletion: '.$e->getMessage());
                    $this->logAction("User deletion failed for {$email}: ".$e->getMessage());

                    return 1;
                }
            } else {
                // Dry run mode - just run through the phases without transaction
                // Phase 2: Delete Resources
                if (! $this->skipResources) {
                    if (! $this->deleteResources()) {
                        $this->info('User deletion would be cancelled at resource deletion phase.');

                        return 0;
                    }
                }

                // Phase 3: Delete Servers
                if (! $this->deleteServers()) {
                    $this->info('User deletion would be cancelled at server deletion phase.');

                    return 0;
                }

                // Phase 4: Handle Teams
                if (! $this->handleTeams()) {
                    $this->info('User deletion would be cancelled at team handling phase.');

                    return 0;
                }

                // Phase 5: Cancel Stripe Subscriptions
                if (! $this->skipStripe && isCloud()) {
                    if (! $this->cancelStripeSubscriptions()) {
                        $this->info('User deletion would be cancelled at Stripe cancellation phase.');

                        return 0;
                    }
                }

                // Phase 6: Delete User Profile
                if (! $this->deleteUserProfile()) {
                    $this->info('User deletion would be cancelled at final phase.');

                    return 0;
                }

                $this->newLine();
                $this->info('✅ DRY RUN completed successfully! No data was deleted.');
            }

            return 0;
        } finally {
            // Ensure lock is always released
            $lock->release();
        }
    }

    private function showUserOverview(): bool
    {
        $this->info('═══════════════════════════════════════');
        $this->info('PHASE 1: USER OVERVIEW');
        $this->info('═══════════════════════════════════════');
        $this->newLine();

        $teams = $this->user->teams;
        $ownedTeams = $teams->filter(fn ($team) => $team->pivot->role === 'owner');
        $memberTeams = $teams->filter(fn ($team) => $team->pivot->role !== 'owner');

        // Collect all servers from all teams
        $allServers = collect();
        $allApplications = collect();
        $allDatabases = collect();
        $allServices = collect();
        $activeSubscriptions = collect();

        foreach ($teams as $team) {
            $servers = $team->servers;
            $allServers = $allServers->merge($servers);

            foreach ($servers as $server) {
                $resources = $server->definedResources();
                foreach ($resources as $resource) {
                    if ($resource instanceof \App\Models\Application) {
                        $allApplications->push($resource);
                    } elseif ($resource instanceof \App\Models\Service) {
                        $allServices->push($resource);
                    } else {
                        $allDatabases->push($resource);
                    }
                }
            }

            if ($team->subscription && $team->subscription->stripe_subscription_id) {
                $activeSubscriptions->push($team->subscription);
            }
        }

        $this->table(
            ['Property', 'Value'],
            [
                ['User', $this->user->email],
                ['User ID', $this->user->id],
                ['Created', $this->user->created_at->format('Y-m-d H:i:s')],
                ['Last Login', $this->user->updated_at->format('Y-m-d H:i:s')],
                ['Teams (Total)', $teams->count()],
                ['Teams (Owner)', $ownedTeams->count()],
                ['Teams (Member)', $memberTeams->count()],
                ['Servers', $allServers->unique('id')->count()],
                ['Applications', $allApplications->count()],
                ['Databases', $allDatabases->count()],
                ['Services', $allServices->count()],
                ['Active Stripe Subscriptions', $activeSubscriptions->count()],
            ]
        );

        $this->newLine();

        $this->warn('⚠️  WARNING: This will permanently delete the user and all associated data!');
        $this->newLine();

        if (! $this->confirm('Do you want to continue with the deletion process?', false)) {
            return false;
        }

        return true;
    }

    private function deleteResources(): bool
    {
        $this->newLine();
        $this->info('═══════════════════════════════════════');
        $this->info('PHASE 2: DELETE RESOURCES');
        $this->info('═══════════════════════════════════════');
        $this->newLine();

        $action = new DeleteUserResources($this->user, $this->isDryRun);
        $resources = $action->getResourcesPreview();

        if ($resources['applications']->isEmpty() &&
            $resources['databases']->isEmpty() &&
            $resources['services']->isEmpty()) {
            $this->info('No resources to delete.');

            return true;
        }

        $this->info('Resources to be deleted:');
        $this->newLine();

        if ($resources['applications']->isNotEmpty()) {
            $this->warn("Applications to be deleted ({$resources['applications']->count()}):");
            $this->table(
                ['Name', 'UUID', 'Server', 'Status'],
                $resources['applications']->map(function ($app) {
                    return [
                        $app->name,
                        $app->uuid,
                        $app->destination->server->name,
                        $app->status ?? 'unknown',
                    ];
                })->toArray()
            );
            $this->newLine();
        }

        if ($resources['databases']->isNotEmpty()) {
            $this->warn("Databases to be deleted ({$resources['databases']->count()}):");
            $this->table(
                ['Name', 'Type', 'UUID', 'Server'],
                $resources['databases']->map(function ($db) {
                    return [
                        $db->name,
                        class_basename($db),
                        $db->uuid,
                        $db->destination->server->name,
                    ];
                })->toArray()
            );
            $this->newLine();
        }

        if ($resources['services']->isNotEmpty()) {
            $this->warn("Services to be deleted ({$resources['services']->count()}):");
            $this->table(
                ['Name', 'UUID', 'Server'],
                $resources['services']->map(function ($service) {
                    return [
                        $service->name,
                        $service->uuid,
                        $service->server->name,
                    ];
                })->toArray()
            );
            $this->newLine();
        }

        $this->error('⚠️  THIS ACTION CANNOT BE UNDONE!');
        if (! $this->confirm('Are you sure you want to delete all these resources?', false)) {
            return false;
        }

        if (! $this->isDryRun) {
            $this->info('Deleting resources...');
            $result = $action->execute();
            $this->info("Deleted: {$result['applications']} applications, {$result['databases']} databases, {$result['services']} services");
            $this->logAction("Deleted resources for user {$this->user->email}: {$result['applications']} apps, {$result['databases']} databases, {$result['services']} services");
        }

        return true;
    }

    private function deleteServers(): bool
    {
        $this->newLine();
        $this->info('═══════════════════════════════════════');
        $this->info('PHASE 3: DELETE SERVERS');
        $this->info('═══════════════════════════════════════');
        $this->newLine();

        $action = new DeleteUserServers($this->user, $this->isDryRun);
        $servers = $action->getServersPreview();

        if ($servers->isEmpty()) {
            $this->info('No servers to delete.');

            return true;
        }

        $this->warn("Servers to be deleted ({$servers->count()}):");
        $this->table(
            ['ID', 'Name', 'IP', 'Description', 'Resources Count'],
            $servers->map(function ($server) {
                $resourceCount = $server->definedResources()->count();

                return [
                    $server->id,
                    $server->name,
                    $server->ip,
                    $server->description ?? '-',
                    $resourceCount,
                ];
            })->toArray()
        );
        $this->newLine();

        $this->error('⚠️  WARNING: Deleting servers will remove all server configurations!');
        if (! $this->confirm('Are you sure you want to delete all these servers?', false)) {
            return false;
        }

        if (! $this->isDryRun) {
            $this->info('Deleting servers...');
            $result = $action->execute();
            $this->info("Deleted {$result['servers']} servers");
            $this->logAction("Deleted {$result['servers']} servers for user {$this->user->email}");
        }

        return true;
    }

    private function handleTeams(): bool
    {
        $this->newLine();
        $this->info('═══════════════════════════════════════');
        $this->info('PHASE 4: HANDLE TEAMS');
        $this->info('═══════════════════════════════════════');
        $this->newLine();

        $action = new DeleteUserTeams($this->user, $this->isDryRun);
        $preview = $action->getTeamsPreview();

        // Check for edge cases first - EXIT IMMEDIATELY if found
        if ($preview['edge_cases']->isNotEmpty()) {
            $this->error('═══════════════════════════════════════');
            $this->error('⚠️  EDGE CASES DETECTED - CANNOT PROCEED');
            $this->error('═══════════════════════════════════════');
            $this->newLine();

            foreach ($preview['edge_cases'] as $edgeCase) {
                $team = $edgeCase['team'];
                $reason = $edgeCase['reason'];
                $this->error("Team: {$team->name} (ID: {$team->id})");
                $this->error("Issue: {$reason}");

                // Show team members for context
                $this->info('Current members:');
                foreach ($team->members as $member) {
                    $role = $member->pivot->role;
                    $this->line("  - {$member->name} ({$member->email}) - Role: {$role}");
                }

                // Check for active resources
                $resourceCount = 0;
                foreach ($team->servers as $server) {
                    $resources = $server->definedResources();
                    $resourceCount += $resources->count();
                }

                if ($resourceCount > 0) {
                    $this->warn("  ⚠️  This team has {$resourceCount} active resources!");
                }

                // Show subscription details if relevant
                if ($team->subscription && $team->subscription->stripe_subscription_id) {
                    $this->warn('  ⚠️  Active Stripe subscription details:');
                    $this->warn("    Subscription ID: {$team->subscription->stripe_subscription_id}");
                    $this->warn("    Customer ID: {$team->subscription->stripe_customer_id}");

                    // Show other owners who could potentially take over
                    $otherOwners = $team->members
                        ->where('id', '!=', $this->user->id)
                        ->filter(function ($member) {
                            return $member->pivot->role === 'owner';
                        });

                    if ($otherOwners->isNotEmpty()) {
                        $this->info('  Other owners who could take over billing:');
                        foreach ($otherOwners as $owner) {
                            $this->line("    - {$owner->name} ({$owner->email})");
                        }
                    }
                }

                $this->newLine();
            }

            $this->error('Please resolve these issues manually before retrying:');

            // Check if any edge case involves subscription payment issues
            $hasSubscriptionIssue = $preview['edge_cases']->contains(function ($edgeCase) {
                return str_contains($edgeCase['reason'], 'Stripe subscription');
            });

            if ($hasSubscriptionIssue) {
                $this->info('For teams with subscription payment issues:');
                $this->info('1. Cancel the subscription through Stripe dashboard, OR');
                $this->info('2. Transfer the subscription to another owner\'s payment method, OR');
                $this->info('3. Have the other owner create a new subscription after cancelling this one');
                $this->newLine();
            }

            $hasNoOwnerReplacement = $preview['edge_cases']->contains(function ($edgeCase) {
                return str_contains($edgeCase['reason'], 'No suitable owner replacement');
            });

            if ($hasNoOwnerReplacement) {
                $this->info('For teams with no suitable owner replacement:');
                $this->info('1. Assign an admin role to a trusted member, OR');
                $this->info('2. Transfer team resources to another team, OR');
                $this->info('3. Delete the team manually if no longer needed');
                $this->newLine();
            }

            $this->error('USER DELETION ABORTED DUE TO EDGE CASES');
            $this->logAction("User deletion aborted for {$this->user->email}: Edge cases in team handling");

            // Exit immediately - don't proceed with deletion
            if (! $this->isDryRun) {
                DB::rollBack();
            }
            exit(1);
        }

        if ($preview['to_delete']->isEmpty() &&
            $preview['to_transfer']->isEmpty() &&
            $preview['to_leave']->isEmpty()) {
            $this->info('No team changes needed.');

            return true;
        }

        if ($preview['to_delete']->isNotEmpty()) {
            $this->warn('Teams to be DELETED (user is the only member):');
            $this->table(
                ['ID', 'Name', 'Resources', 'Subscription'],
                $preview['to_delete']->map(function ($team) {
                    $resourceCount = 0;
                    foreach ($team->servers as $server) {
                        $resourceCount += $server->definedResources()->count();
                    }
                    $hasSubscription = $team->subscription && $team->subscription->stripe_subscription_id
                        ? '⚠️ YES - '.$team->subscription->stripe_subscription_id
                        : 'No';

                    return [
                        $team->id,
                        $team->name,
                        $resourceCount,
                        $hasSubscription,
                    ];
                })->toArray()
            );
            $this->newLine();
        }

        if ($preview['to_transfer']->isNotEmpty()) {
            $this->warn('Teams where ownership will be TRANSFERRED:');
            $this->table(
                ['Team ID', 'Team Name', 'New Owner', 'New Owner Email'],
                $preview['to_transfer']->map(function ($item) {
                    return [
                        $item['team']->id,
                        $item['team']->name,
                        $item['new_owner']->name,
                        $item['new_owner']->email,
                    ];
                })->toArray()
            );
            $this->newLine();
        }

        if ($preview['to_leave']->isNotEmpty()) {
            $this->warn('Teams where user will be REMOVED (other owners/admins exist):');
            $userId = $this->user->id;
            $this->table(
                ['ID', 'Name', 'User Role', 'Other Members'],
                $preview['to_leave']->map(function ($team) use ($userId) {
                    $userRole = $team->members->where('id', $userId)->first()->pivot->role;
                    $otherMembers = $team->members->count() - 1;

                    return [
                        $team->id,
                        $team->name,
                        $userRole,
                        $otherMembers,
                    ];
                })->toArray()
            );
            $this->newLine();
        }

        $this->error('⚠️  WARNING: Team changes affect access control and ownership!');
        if (! $this->confirm('Are you sure you want to proceed with these team changes?', false)) {
            return false;
        }

        if (! $this->isDryRun) {
            $this->info('Processing team changes...');
            $result = $action->execute();
            $this->info("Teams deleted: {$result['deleted']}, ownership transferred: {$result['transferred']}, left: {$result['left']}");
            $this->logAction("Team changes for user {$this->user->email}: deleted {$result['deleted']}, transferred {$result['transferred']}, left {$result['left']}");
        }

        return true;
    }

    private function cancelStripeSubscriptions(): bool
    {
        $this->newLine();
        $this->info('═══════════════════════════════════════');
        $this->info('PHASE 5: CANCEL STRIPE SUBSCRIPTIONS');
        $this->info('═══════════════════════════════════════');
        $this->newLine();

        $action = new CancelSubscription($this->user, $this->isDryRun);
        $subscriptions = $action->getSubscriptionsPreview();

        if ($subscriptions->isEmpty()) {
            $this->info('No Stripe subscriptions to cancel.');

            return true;
        }

        $this->info('Stripe subscriptions to cancel:');
        $this->newLine();

        $totalMonthlyValue = 0;
        foreach ($subscriptions as $subscription) {
            $team = $subscription->team;
            $planId = $subscription->stripe_plan_id;

            // Try to get the price from config
            $monthlyValue = $this->getSubscriptionMonthlyValue($planId);
            $totalMonthlyValue += $monthlyValue;

            $this->line("  - {$subscription->stripe_subscription_id} (Team: {$team->name})");
            if ($monthlyValue > 0) {
                $this->line("    Monthly value: \${$monthlyValue}");
            }
            if ($subscription->stripe_cancel_at_period_end) {
                $this->line('    ⚠️  Already set to cancel at period end');
            }
        }

        if ($totalMonthlyValue > 0) {
            $this->newLine();
            $this->warn("Total monthly value: \${$totalMonthlyValue}");
        }
        $this->newLine();

        $this->error('⚠️  WARNING: Subscriptions will be cancelled IMMEDIATELY (not at period end)!');
        if (! $this->confirm('Are you sure you want to cancel all these subscriptions immediately?', false)) {
            return false;
        }

        if (! $this->isDryRun) {
            $this->info('Cancelling subscriptions...');
            $result = $action->execute();
            $this->info("Cancelled {$result['cancelled']} subscriptions, {$result['failed']} failed");
            if ($result['failed'] > 0 && ! empty($result['errors'])) {
                $this->error('Failed subscriptions:');
                foreach ($result['errors'] as $error) {
                    $this->error("  - {$error}");
                }
            }
            $this->logAction("Cancelled {$result['cancelled']} Stripe subscriptions for user {$this->user->email}");
        }

        return true;
    }

    private function deleteUserProfile(): bool
    {
        $this->newLine();
        $this->info('═══════════════════════════════════════');
        $this->info('PHASE 6: DELETE USER PROFILE');
        $this->info('═══════════════════════════════════════');
        $this->newLine();

        $this->warn('⚠️  FINAL STEP - This action is IRREVERSIBLE!');
        $this->newLine();

        $this->info('User profile to be deleted:');
        $this->table(
            ['Property', 'Value'],
            [
                ['Email', $this->user->email],
                ['Name', $this->user->name],
                ['User ID', $this->user->id],
                ['Created', $this->user->created_at->format('Y-m-d H:i:s')],
                ['Email Verified', $this->user->email_verified_at ? 'Yes' : 'No'],
                ['2FA Enabled', $this->user->two_factor_confirmed_at ? 'Yes' : 'No'],
            ]
        );

        $this->newLine();

        $this->warn("Type 'DELETE {$this->user->email}' to confirm final deletion:");
        $confirmation = $this->ask('Confirmation');

        if ($confirmation !== "DELETE {$this->user->email}") {
            $this->error('Confirmation text does not match. Deletion cancelled.');

            return false;
        }

        if (! $this->isDryRun) {
            $this->info('Deleting user profile...');

            try {
                $this->user->delete();
                $this->info('User profile deleted successfully.');
                $this->logAction("User profile deleted: {$this->user->email}");
            } catch (\Exception $e) {
                $this->error('Failed to delete user profile: '.$e->getMessage());
                $this->logAction("Failed to delete user profile {$this->user->email}: ".$e->getMessage());

                return false;
            }
        }

        return true;
    }

    private function getSubscriptionMonthlyValue(string $planId): int
    {
        // Try to get pricing from subscription metadata or config
        // Since we're using dynamic pricing, return 0 for now
        // This could be enhanced by fetching the actual price from Stripe API

        // Check if this is a dynamic pricing plan
        $dynamicMonthlyPlanId = config('subscription.stripe_price_id_dynamic_monthly');
        $dynamicYearlyPlanId = config('subscription.stripe_price_id_dynamic_yearly');

        if ($planId === $dynamicMonthlyPlanId || $planId === $dynamicYearlyPlanId) {
            // For dynamic pricing, we can't determine the exact amount without calling Stripe API
            // Return 0 to indicate dynamic/usage-based pricing
            return 0;
        }

        // For any other plans, return 0 as we don't have hardcoded prices
        return 0;
    }

    private function logAction(string $message): void
    {
        $logMessage = "[CloudDeleteUser] {$message}";

        if ($this->isDryRun) {
            $logMessage = "[DRY RUN] {$logMessage}";
        }

        Log::channel('single')->info($logMessage);

        // Also log to a dedicated user deletion log file
        $logFile = storage_path('logs/user-deletions.log');

        // Ensure the logs directory exists
        $logDir = dirname($logFile);
        if (! is_dir($logDir)) {
            mkdir($logDir, 0755, true);
        }

        $timestamp = now()->format('Y-m-d H:i:s');
        file_put_contents($logFile, "[{$timestamp}] {$logMessage}\n", FILE_APPEND | LOCK_EX);
    }
}
