<?php

namespace App\Console\Commands;

use App\Actions\Stripe\CancelSubscription;
use App\Actions\User\DeleteUserResources;
use App\Actions\User\DeleteUserServers;
use App\Actions\User\DeleteUserTeams;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class AdminDeleteUser extends Command
{
    protected $signature = 'admin:delete-user {email}
                            {--dry-run : Preview what will be deleted without actually deleting}
                            {--skip-stripe : Skip Stripe subscription cancellation}
                            {--skip-resources : Skip resource deletion}
                            {--auto-confirm : Skip all confirmation prompts between phases}
                            {--force : Bypass the lock check and force deletion (use with caution)}';

    protected $description = 'Delete a user with comprehensive resource cleanup and phase-by-phase confirmation (works on cloud and self-hosted)';

    private bool $isDryRun = false;

    private bool $skipStripe = false;

    private bool $skipResources = false;

    private User $user;

    private $lock;

    private array $deletionState = [
        'phase_1_overview' => false,
        'phase_2_resources' => false,
        'phase_3_servers' => false,
        'phase_4_teams' => false,
        'phase_5_user_profile' => false,
        'phase_6_stripe' => false,
        'db_committed' => false,
    ];

    public function handle()
    {
        // Register signal handlers for graceful shutdown (Ctrl+C handling)
        $this->registerSignalHandlers();

        $email = $this->argument('email');
        $this->isDryRun = $this->option('dry-run');
        $this->skipStripe = $this->option('skip-stripe');
        $this->skipResources = $this->option('skip-resources');
        $force = $this->option('force');

        if ($force) {
            $this->warn('⚠️  FORCE MODE - Lock check will be bypassed');
            $this->warn('   Use this flag only if you are certain no other deletion is running');
            $this->newLine();
        }

        if ($this->isDryRun) {
            $this->info('🔍 DRY RUN MODE - No data will be deleted');
            $this->newLine();
        }

        if ($this->output->isVerbose()) {
            $this->info('📊 VERBOSE MODE - Full stack traces will be shown on errors');
            $this->newLine();
        } else {
            $this->comment('💡 Tip: Use -v flag for detailed error stack traces');
            $this->newLine();
        }

        if (! $this->isDryRun && ! $this->option('auto-confirm')) {
            $this->info('🔄 INTERACTIVE MODE - You will be asked to confirm after each phase');
            $this->comment('   Use --auto-confirm to skip phase confirmations');
            $this->newLine();
        }

        // Notify about instance type and Stripe
        if (isCloud()) {
            $this->comment('☁️  Cloud instance - Stripe subscriptions will be handled');
        } else {
            $this->comment('🏠 Self-hosted instance - Stripe operations will be skipped');
        }
        $this->newLine();

        try {
            $this->user = User::whereEmail($email)->firstOrFail();
        } catch (\Exception $e) {
            $this->error("User with email '{$email}' not found.");

            return 1;
        }

        // Implement file lock to prevent concurrent deletions of the same user
        $lockKey = "user_deletion_{$this->user->id}";
        $this->lock = Cache::lock($lockKey, 600); // 10 minute lock

        if (! $force) {
            if (! $this->lock->get()) {
                $this->error('Another deletion process is already running for this user.');
                $this->error('Use --force to bypass this lock (use with extreme caution).');
                $this->logAction("Deletion blocked for user {$email}: Another process is already running");

                return 1;
            }
        } else {
            // In force mode, try to get lock but continue even if it fails
            if (! $this->lock->get()) {
                $this->warn('⚠️  Lock exists but proceeding due to --force flag');
                $this->warn('   There may be another deletion process running!');
                $this->newLine();
            }
        }

        try {
            $this->logAction("Starting user deletion process for: {$email}");

            // Phase 1: Show User Overview (outside transaction)
            if (! $this->showUserOverview()) {
                $this->info('User deletion cancelled by operator.');

                return 0;
            }
            $this->deletionState['phase_1_overview'] = true;

            // If not dry run, wrap DB operations in a transaction
            // NOTE: Stripe cancellations happen AFTER commit to avoid inconsistent state
            if (! $this->isDryRun) {
                try {
                    DB::beginTransaction();

                    // Phase 2: Delete Resources
                    // WARNING: This triggers Docker container deletion via SSH which CANNOT be rolled back
                    if (! $this->skipResources) {
                        if (! $this->deleteResources()) {
                            DB::rollBack();
                            $this->displayErrorState('Phase 2: Resource Deletion');
                            $this->error('❌ User deletion failed at resource deletion phase.');
                            $this->warn('⚠️  Some Docker containers may have been deleted on remote servers and cannot be restored.');
                            $this->displayRecoverySteps();

                            return 1;
                        }
                    }
                    $this->deletionState['phase_2_resources'] = true;

                    // Confirmation to continue after Phase 2
                    if (! $this->skipResources && ! $this->option('auto-confirm')) {
                        $this->newLine();
                        if (! $this->confirm('Phase 2 completed. Continue to Phase 3 (Delete Servers)?', true)) {
                            DB::rollBack();
                            $this->info('User deletion cancelled by operator after Phase 2.');
                            $this->info('Database changes have been rolled back.');

                            return 0;
                        }
                    }

                    // Phase 3: Delete Servers
                    // WARNING: This may trigger cleanup operations on remote servers which CANNOT be rolled back
                    if (! $this->deleteServers()) {
                        DB::rollBack();
                        $this->displayErrorState('Phase 3: Server Deletion');
                        $this->error('❌ User deletion failed at server deletion phase.');
                        $this->warn('⚠️  Some server cleanup operations may have been performed and cannot be restored.');
                        $this->displayRecoverySteps();

                        return 1;
                    }
                    $this->deletionState['phase_3_servers'] = true;

                    // Confirmation to continue after Phase 3
                    if (! $this->option('auto-confirm')) {
                        $this->newLine();
                        if (! $this->confirm('Phase 3 completed. Continue to Phase 4 (Handle Teams)?', true)) {
                            DB::rollBack();
                            $this->info('User deletion cancelled by operator after Phase 3.');
                            $this->info('Database changes have been rolled back.');

                            return 0;
                        }
                    }

                    // Phase 4: Handle Teams
                    if (! $this->handleTeams()) {
                        DB::rollBack();
                        $this->displayErrorState('Phase 4: Team Handling');
                        $this->error('❌ User deletion failed at team handling phase.');
                        $this->displayRecoverySteps();

                        return 1;
                    }
                    $this->deletionState['phase_4_teams'] = true;

                    // Confirmation to continue after Phase 4
                    if (! $this->option('auto-confirm')) {
                        $this->newLine();
                        if (! $this->confirm('Phase 4 completed. Continue to Phase 5 (Delete User Profile)?', true)) {
                            DB::rollBack();
                            $this->info('User deletion cancelled by operator after Phase 4.');
                            $this->info('Database changes have been rolled back.');

                            return 0;
                        }
                    }

                    // Phase 5: Delete User Profile
                    if (! $this->deleteUserProfile()) {
                        DB::rollBack();
                        $this->displayErrorState('Phase 5: User Profile Deletion');
                        $this->error('❌ User deletion failed at user profile deletion phase.');
                        $this->displayRecoverySteps();

                        return 1;
                    }
                    $this->deletionState['phase_5_user_profile'] = true;

                    // CRITICAL CONFIRMATION: Database commit is next (PERMANENT)
                    if (! $this->option('auto-confirm')) {
                        $this->newLine();
                        $this->warn('⚠️  CRITICAL DECISION POINT');
                        $this->warn('Next step: COMMIT database changes (PERMANENT and IRREVERSIBLE)');
                        $this->warn('All resources, servers, teams, and user profile will be permanently deleted');
                        $this->newLine();
                        if (! $this->confirm('Phase 5 completed. Commit database changes? (THIS IS PERMANENT)', false)) {
                            DB::rollBack();
                            $this->info('User deletion cancelled by operator before commit.');
                            $this->info('Database changes have been rolled back.');
                            $this->warn('⚠️  Note: Some Docker containers may have been deleted on remote servers.');

                            return 0;
                        }
                    }

                    // Commit the database transaction
                    DB::commit();
                    $this->deletionState['db_committed'] = true;

                    $this->newLine();
                    $this->info('✅ Database operations completed successfully!');
                    $this->info('✅ Transaction committed - database changes are now PERMANENT.');
                    $this->logAction("Database deletion completed for: {$email}");

                    // Confirmation to continue to Stripe (after commit)
                    if (! $this->skipStripe && isCloud() && ! $this->option('auto-confirm')) {
                        $this->newLine();
                        $this->warn('⚠️  Database changes are committed (permanent)');
                        $this->info('Next: Cancel Stripe subscriptions');
                        if (! $this->confirm('Continue to Phase 6 (Cancel Stripe Subscriptions)?', true)) {
                            $this->warn('User deletion stopped after database commit.');
                            $this->error('⚠️  IMPORTANT: User deleted from database but Stripe subscriptions remain active!');
                            $this->error('You must cancel subscriptions manually in Stripe Dashboard.');
                            $this->error('Go to: https://dashboard.stripe.com/');
                            $this->error('Search for: '.$email);

                            return 1;
                        }
                    }

                    // Phase 6: Cancel Stripe Subscriptions (AFTER DB commit)
                    // This is done AFTER commit because Stripe API calls cannot be rolled back
                    // If this fails, DB changes are already committed but subscriptions remain active
                    if (! $this->skipStripe && isCloud()) {
                        if (! $this->cancelStripeSubscriptions()) {
                            $this->newLine();
                            $this->error('═══════════════════════════════════════');
                            $this->error('⚠️  CRITICAL: INCONSISTENT STATE DETECTED');
                            $this->error('═══════════════════════════════════════');
                            $this->error('✓ User data DELETED from database (committed)');
                            $this->error('✗ Stripe subscription cancellation FAILED');
                            $this->newLine();
                            $this->displayErrorState('Phase 6: Stripe Cancellation (Post-Commit)');
                            $this->newLine();
                            $this->error('MANUAL ACTION REQUIRED:');
                            $this->error('1. Go to Stripe Dashboard: https://dashboard.stripe.com/');
                            $this->error('2. Search for customer email: '.$email);
                            $this->error('3. Cancel all active subscriptions');
                            $this->error('4. Check storage/logs/user-deletions.log for subscription IDs');
                            $this->newLine();
                            $this->logAction("INCONSISTENT STATE: User {$email} deleted but Stripe cancellation failed");

                            return 1;
                        }
                    }
                    $this->deletionState['phase_6_stripe'] = true;

                    $this->newLine();
                    $this->info('✅ User deletion completed successfully!');
                    $this->logAction("User deletion completed for: {$email}");

                } catch (\Exception $e) {
                    DB::rollBack();
                    $this->newLine();
                    $this->error('═══════════════════════════════════════');
                    $this->error('❌ EXCEPTION DURING USER DELETION');
                    $this->error('═══════════════════════════════════════');
                    $this->error('Exception: '.get_class($e));
                    $this->error('Message: '.$e->getMessage());
                    $this->error('File: '.$e->getFile().':'.$e->getLine());
                    $this->newLine();

                    if ($this->output->isVerbose()) {
                        $this->error('Stack Trace:');
                        $this->error($e->getTraceAsString());
                        $this->newLine();
                    } else {
                        $this->info('Run with -v for full stack trace');
                        $this->newLine();
                    }

                    $this->displayErrorState('Exception during execution');
                    $this->displayRecoverySteps();

                    $this->logAction("User deletion failed for {$email}: {$e->getMessage()} in {$e->getFile()}:{$e->getLine()}");

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

                // Phase 5: Delete User Profile
                if (! $this->deleteUserProfile()) {
                    $this->info('User deletion would be cancelled at user profile deletion phase.');

                    return 0;
                }

                // Phase 6: Cancel Stripe Subscriptions (shown after DB operations in dry run too)
                if (! $this->skipStripe && isCloud()) {
                    if (! $this->cancelStripeSubscriptions()) {
                        $this->info('User deletion would be cancelled at Stripe cancellation phase.');

                        return 0;
                    }
                }

                $this->newLine();
                $this->info('✅ DRY RUN completed successfully! No data was deleted.');
            }

            return 0;
        } finally {
            // Ensure lock is always released
            $this->releaseLock();
        }
    }

    private function showUserOverview(): bool
    {
        $this->info('═══════════════════════════════════════');
        $this->info('PHASE 1: USER OVERVIEW');
        $this->info('═══════════════════════════════════════');
        $this->newLine();

        $teams = $this->user->teams()->get();
        $ownedTeams = $teams->filter(fn ($team) => $team->pivot->role === 'owner');
        $memberTeams = $teams->filter(fn ($team) => $team->pivot->role !== 'owner');

        // Collect servers and resources ONLY from teams that will be FULLY DELETED
        // This means: user is owner AND is the ONLY member
        //
        // Resources from these teams will NOT be deleted:
        // - Teams where user is just a member
        // - Teams where user is owner but has other members (will be transferred/user removed)
        $allServers = collect();
        $allApplications = collect();
        $allDatabases = collect();
        $allServices = collect();
        $activeSubscriptions = collect();

        foreach ($teams as $team) {
            $userRole = $team->pivot->role;
            $memberCount = $team->members->count();

            // Only show resources from teams where user is the ONLY member
            // These are the teams that will be fully deleted
            if ($userRole !== 'owner' || $memberCount > 1) {
                continue;
            }

            $servers = $team->servers()->get();
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

            // Only collect subscriptions on cloud instances
            if (isCloud() && $team->subscription && $team->subscription->stripe_subscription_id) {
                $activeSubscriptions->push($team->subscription);
            }
        }

        // Build table data
        $tableData = [
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
        ];

        // Only show Stripe subscriptions on cloud instances
        if (isCloud()) {
            $tableData[] = ['Active Stripe Subscriptions', $activeSubscriptions->count()];
        }

        $this->table(['Property', 'Value'], $tableData);

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
            try {
                $result = $action->execute();
                $this->info("✓ Deleted: {$result['applications']} applications, {$result['databases']} databases, {$result['services']} services");
                $this->logAction("Deleted resources for user {$this->user->email}: {$result['applications']} apps, {$result['databases']} databases, {$result['services']} services");
            } catch (\Exception $e) {
                $this->error('Failed to delete resources:');
                $this->error('Exception: '.get_class($e));
                $this->error('Message: '.$e->getMessage());
                $this->error('File: '.$e->getFile().':'.$e->getLine());

                if ($this->output->isVerbose()) {
                    $this->error('Stack Trace:');
                    $this->error($e->getTraceAsString());
                }

                throw $e; // Re-throw to trigger rollback
            }
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
            try {
                $result = $action->execute();
                $this->info("✓ Deleted {$result['servers']} servers");
                $this->logAction("Deleted {$result['servers']} servers for user {$this->user->email}");
            } catch (\Exception $e) {
                $this->error('Failed to delete servers:');
                $this->error('Exception: '.get_class($e));
                $this->error('Message: '.$e->getMessage());
                $this->error('File: '.$e->getFile().':'.$e->getLine());

                if ($this->output->isVerbose()) {
                    $this->error('Stack Trace:');
                    $this->error($e->getTraceAsString());
                }

                throw $e; // Re-throw to trigger rollback
            }
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
                foreach ($team->servers()->get() as $server) {
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

            // Return false to trigger proper cleanup and lock release
            return false;
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
                    foreach ($team->servers()->get() as $server) {
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
            try {
                $result = $action->execute();
                $this->info("✓ Teams deleted: {$result['deleted']}, ownership transferred: {$result['transferred']}, left: {$result['left']}");
                $this->logAction("Team changes for user {$this->user->email}: deleted {$result['deleted']}, transferred {$result['transferred']}, left {$result['left']}");
            } catch (\Exception $e) {
                $this->error('Failed to process team changes:');
                $this->error('Exception: '.get_class($e));
                $this->error('Message: '.$e->getMessage());
                $this->error('File: '.$e->getFile().':'.$e->getLine());

                if ($this->output->isVerbose()) {
                    $this->error('Stack Trace:');
                    $this->error($e->getTraceAsString());
                }

                throw $e; // Re-throw to trigger rollback
            }
        }

        return true;
    }

    private function cancelStripeSubscriptions(): bool
    {
        $this->newLine();
        $this->info('═══════════════════════════════════════');
        $this->info('PHASE 6: CANCEL STRIPE SUBSCRIPTIONS');
        $this->info('═══════════════════════════════════════');
        $this->newLine();

        $action = new CancelSubscription($this->user, $this->isDryRun);
        $subscriptions = $action->getSubscriptionsPreview();

        if ($subscriptions->isEmpty()) {
            $this->info('No Stripe subscriptions to cancel.');

            return true;
        }

        // Verify subscriptions in Stripe before showing details
        $this->info('Verifying subscriptions in Stripe...');
        $verification = $action->verifySubscriptionsInStripe();

        if (! empty($verification['errors'])) {
            $this->warn('⚠️  Errors occurred during verification:');
            foreach ($verification['errors'] as $error) {
                $this->warn("  - {$error}");
            }
            $this->newLine();
        }

        if ($verification['not_found']->isNotEmpty()) {
            $this->warn('⚠️  Subscriptions not found or inactive in Stripe:');
            foreach ($verification['not_found'] as $item) {
                $subscription = $item['subscription'];
                $reason = $item['reason'];
                $this->line("  - {$subscription->stripe_subscription_id} (Team: {$subscription->team->name}) - {$reason}");
            }
            $this->newLine();
        }

        if ($verification['verified']->isEmpty()) {
            $this->info('No active subscriptions found in Stripe to cancel.');

            return true;
        }

        $this->info('Active Stripe subscriptions to cancel:');
        $this->newLine();

        $totalMonthlyValue = 0;
        foreach ($verification['verified'] as $item) {
            $subscription = $item['subscription'];
            $stripeStatus = $item['stripe_status'];
            $team = $subscription->team;
            $planId = $subscription->stripe_plan_id;

            // Try to get the price from config
            $monthlyValue = $this->getSubscriptionMonthlyValue($planId);
            $totalMonthlyValue += $monthlyValue;

            $this->line("  - {$subscription->stripe_subscription_id} (Team: {$team->name})");
            $this->line("    Stripe Status: {$stripeStatus}");
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
        $this->warn('⚠️  NOTE: This operation happens AFTER database commit and cannot be rolled back!');
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

                return false;
            }
            $this->logAction("Cancelled {$result['cancelled']} Stripe subscriptions for user {$this->user->email}");
        }

        return true;
    }

    private function deleteUserProfile(): bool
    {
        $this->newLine();
        $this->info('═══════════════════════════════════════');
        $this->info('PHASE 5: DELETE USER PROFILE');
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
                $this->info('✓ User profile deleted successfully.');
                $this->logAction("User profile deleted: {$this->user->email}");
            } catch (\Exception $e) {
                $this->error('Failed to delete user profile:');
                $this->error('Exception: '.get_class($e));
                $this->error('Message: '.$e->getMessage());
                $this->error('File: '.$e->getFile().':'.$e->getLine());

                if ($this->output->isVerbose()) {
                    $this->error('Stack Trace:');
                    $this->error($e->getTraceAsString());
                }

                $this->logAction("Failed to delete user profile {$this->user->email}: {$e->getMessage()}");

                throw $e; // Re-throw to trigger rollback
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

    private function displayErrorState(string $failedAt): void
    {
        $this->newLine();
        $this->error('═══════════════════════════════════════');
        $this->error('DELETION STATE AT FAILURE');
        $this->error('═══════════════════════════════════════');
        $this->error("Failed at: {$failedAt}");
        $this->newLine();

        $stateTable = [];
        foreach ($this->deletionState as $phase => $completed) {
            $phaseLabel = str_replace('_', ' ', ucwords($phase, '_'));
            $status = $completed ? '✓ Completed' : '✗ Not completed';
            $stateTable[] = [$phaseLabel, $status];
        }

        $this->table(['Phase', 'Status'], $stateTable);
        $this->newLine();

        // Show what was rolled back vs what remains
        if ($this->deletionState['db_committed']) {
            $this->error('⚠️  DATABASE COMMITTED - Changes CANNOT be rolled back!');
        } else {
            $this->info('✓ Database changes were ROLLED BACK');
        }

        $this->newLine();
        $this->error('User email: '.$this->user->email);
        $this->error('User ID: '.$this->user->id);
        $this->error('Timestamp: '.now()->format('Y-m-d H:i:s'));
        $this->newLine();
    }

    private function displayRecoverySteps(): void
    {
        $this->error('═══════════════════════════════════════');
        $this->error('RECOVERY STEPS');
        $this->error('═══════════════════════════════════════');

        if (! $this->deletionState['db_committed']) {
            $this->info('✓ Database was rolled back - no recovery needed for database');
            $this->newLine();

            if ($this->deletionState['phase_2_resources'] || $this->deletionState['phase_3_servers']) {
                $this->warn('However, some remote operations may have occurred:');
                $this->newLine();

                if ($this->deletionState['phase_2_resources']) {
                    $this->warn('Phase 2 (Resources) was attempted:');
                    $this->warn('- Check remote servers for orphaned Docker containers');
                    $this->warn('- Use: docker ps -a | grep coolify');
                    $this->warn('- Manually remove if needed: docker rm -f <container_id>');
                    $this->newLine();
                }

                if ($this->deletionState['phase_3_servers']) {
                    $this->warn('Phase 3 (Servers) was attempted:');
                    $this->warn('- Check for orphaned server configurations');
                    $this->warn('- Verify SSH access to servers listed for this user');
                    $this->newLine();
                }
            }
        } else {
            $this->error('⚠️  DATABASE WAS COMMITTED - Manual recovery required!');
            $this->newLine();
            $this->error('The following data has been PERMANENTLY deleted:');

            if ($this->deletionState['phase_5_user_profile']) {
                $this->error('- User profile (email: '.$this->user->email.')');
            }
            if ($this->deletionState['phase_4_teams']) {
                $this->error('- Team memberships and owned teams');
            }
            if ($this->deletionState['phase_3_servers']) {
                $this->error('- Server records and configurations');
            }
            if ($this->deletionState['phase_2_resources']) {
                $this->error('- Applications, databases, and services');
            }

            $this->newLine();

            if (! $this->deletionState['phase_6_stripe']) {
                $this->error('Stripe subscriptions were NOT cancelled:');
                $this->error('1. Go to Stripe Dashboard: https://dashboard.stripe.com/');
                $this->error('2. Search for: '.$this->user->email);
                $this->error('3. Cancel all active subscriptions manually');
                $this->newLine();
            }
        }

        $this->error('Log file: storage/logs/user-deletions.log');
        $this->error('Check logs for detailed error information');
        $this->newLine();
    }

    /**
     * Register signal handlers for graceful shutdown on Ctrl+C (SIGINT) and SIGTERM
     */
    private function registerSignalHandlers(): void
    {
        if (! function_exists('pcntl_signal')) {
            // pcntl extension not available, skip signal handling
            return;
        }

        // Handle Ctrl+C (SIGINT)
        pcntl_signal(SIGINT, function () {
            $this->newLine();
            $this->warn('═══════════════════════════════════════');
            $this->warn('⚠️  PROCESS INTERRUPTED (Ctrl+C)');
            $this->warn('═══════════════════════════════════════');
            $this->info('Cleaning up and releasing lock...');
            $this->releaseLock();
            $this->info('Lock released. Exiting gracefully.');
            exit(130); // Standard exit code for SIGINT
        });

        // Handle SIGTERM
        pcntl_signal(SIGTERM, function () {
            $this->newLine();
            $this->warn('═══════════════════════════════════════');
            $this->warn('⚠️  PROCESS TERMINATED (SIGTERM)');
            $this->warn('═══════════════════════════════════════');
            $this->info('Cleaning up and releasing lock...');
            $this->releaseLock();
            $this->info('Lock released. Exiting gracefully.');
            exit(143); // Standard exit code for SIGTERM
        });

        // Enable async signal handling
        pcntl_async_signals(true);
    }

    /**
     * Release the lock if it exists
     */
    private function releaseLock(): void
    {
        if ($this->lock) {
            try {
                $this->lock->release();
            } catch (\Exception $e) {
                // Silently ignore lock release errors
                // Lock will expire after 10 minutes anyway
            }
        }
    }
}
