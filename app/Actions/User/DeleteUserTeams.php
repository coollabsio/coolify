<?php

namespace App\Actions\User;

use App\Models\Team;
use App\Models\User;

class DeleteUserTeams
{
    private User $user;

    private bool $isDryRun;

    public function __construct(User $user, bool $isDryRun = false)
    {
        $this->user = $user;
        $this->isDryRun = $isDryRun;
    }

    public function getTeamsPreview(): array
    {
        $teamsToDelete = collect();
        $teamsToTransfer = collect();
        $teamsToLeave = collect();
        $edgeCases = collect();

        $teams = $this->user->teams;

        foreach ($teams as $team) {
            // Skip root team (ID 0)
            if ($team->id === 0) {
                continue;
            }

            $userRole = $team->pivot->role;
            $memberCount = $team->members->count();

            if ($memberCount === 1) {
                // User is alone in the team - delete it
                $teamsToDelete->push($team);
            } elseif ($userRole === 'owner') {
                // Check if there are other owners
                $otherOwners = $team->members
                    ->where('id', '!=', $this->user->id)
                    ->filter(function ($member) {
                        return $member->pivot->role === 'owner';
                    });

                if ($otherOwners->isNotEmpty()) {
                    // There are other owners, but check if this user is paying for the subscription
                    if ($this->isUserPayingForTeamSubscription($team)) {
                        // User is paying for the subscription - this is an edge case
                        $edgeCases->push([
                            'team' => $team,
                            'reason' => 'User is paying for the team\'s Stripe subscription but there are other owners. The subscription needs to be cancelled or transferred to another owner\'s payment method.',
                        ]);
                    } else {
                        // There are other owners and user is not paying, just remove this user
                        $teamsToLeave->push($team);
                    }
                } else {
                    // User is the only owner, check for replacement
                    $newOwner = $this->findNewOwner($team);
                    if ($newOwner) {
                        $teamsToTransfer->push([
                            'team' => $team,
                            'new_owner' => $newOwner,
                        ]);
                    } else {
                        // No suitable replacement found - this is an edge case
                        $edgeCases->push([
                            'team' => $team,
                            'reason' => 'No suitable owner replacement found. Team has only regular members without admin privileges.',
                        ]);
                    }
                }
            } else {
                // User is just a member - remove them from the team
                $teamsToLeave->push($team);
            }
        }

        return [
            'to_delete' => $teamsToDelete,
            'to_transfer' => $teamsToTransfer,
            'to_leave' => $teamsToLeave,
            'edge_cases' => $edgeCases,
        ];
    }

    public function execute(): array
    {
        if ($this->isDryRun) {
            return [
                'deleted' => 0,
                'transferred' => 0,
                'left' => 0,
            ];
        }

        $counts = [
            'deleted' => 0,
            'transferred' => 0,
            'left' => 0,
        ];

        $preview = $this->getTeamsPreview();

        // Check for edge cases - should not happen here as we check earlier, but be safe
        if ($preview['edge_cases']->isNotEmpty()) {
            throw new \Exception('Edge cases detected during execution. This should not happen.');
        }

        // Delete teams where user is alone
        foreach ($preview['to_delete'] as $team) {
            try {
                // The Team model's deleting event will handle cleanup of:
                // - private keys
                // - sources
                // - tags
                // - environment variables
                // - s3 storages
                // - notification settings
                $team->delete();
                $counts['deleted']++;
            } catch (\Exception $e) {
                \Log::error("Failed to delete team {$team->id}: ".$e->getMessage());
                throw $e; // Re-throw to trigger rollback
            }
        }

        // Transfer ownership for teams where user is owner but not alone
        foreach ($preview['to_transfer'] as $item) {
            try {
                $team = $item['team'];
                $newOwner = $item['new_owner'];

                // Update the new owner's role to owner
                $team->members()->updateExistingPivot($newOwner->id, ['role' => 'owner']);

                // Remove the current user from the team
                $team->members()->detach($this->user->id);

                $counts['transferred']++;
            } catch (\Exception $e) {
                \Log::error("Failed to transfer ownership of team {$item['team']->id}: ".$e->getMessage());
                throw $e; // Re-throw to trigger rollback
            }
        }

        // Remove user from teams where they're just a member
        foreach ($preview['to_leave'] as $team) {
            try {
                $team->members()->detach($this->user->id);
                $counts['left']++;
            } catch (\Exception $e) {
                \Log::error("Failed to remove user from team {$team->id}: ".$e->getMessage());
                throw $e; // Re-throw to trigger rollback
            }
        }

        return $counts;
    }

    private function findNewOwner(Team $team): ?User
    {
        // Only look for admins as potential new owners
        // We don't promote regular members automatically
        $otherAdmin = $team->members
            ->where('id', '!=', $this->user->id)
            ->filter(function ($member) {
                return $member->pivot->role === 'admin';
            })
            ->first();

        return $otherAdmin;
    }

    private function isUserPayingForTeamSubscription(Team $team): bool
    {
        if (! $team->subscription || ! $team->subscription->stripe_customer_id) {
            return false;
        }

        // In Stripe, we need to check if the customer email matches the user's email
        // This would require a Stripe API call to get customer details
        // For now, we'll check if the subscription was created by this user

        // Alternative approach: Check if user is the one who initiated the subscription
        // We could store this information when the subscription is created
        // For safety, we'll assume if there's an active subscription and multiple owners,
        // we should treat it as an edge case that needs manual review

        if ($team->subscription->stripe_subscription_id &&
            $team->subscription->stripe_invoice_paid) {
            // Active subscription exists - we should be cautious
            return true;
        }

        return false;
    }
}
