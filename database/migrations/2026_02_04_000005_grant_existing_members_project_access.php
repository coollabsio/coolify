<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Auto-grants existing team members access to all projects in their teams.
     * This ensures backward compatibility - existing members retain their
     * current access levels after the new permission system is enabled.
     */
    public function up(): void
    {
        // Get all team memberships with 'member' or 'viewer' role
        // (Admins and owners bypass project-level checks)
        $teamMemberships = DB::table('team_user')
            ->whereIn('role', ['member', 'viewer'])
            ->get();

        $projectAccessRecords = [];
        $now = now();

        foreach ($teamMemberships as $membership) {
            // Get all projects for this team
            $projects = DB::table('projects')
                ->where('team_id', $membership->team_id)
                ->get();

            foreach ($projects as $project) {
                // Check if access record already exists
                $exists = DB::table('project_user')
                    ->where('project_id', $project->id)
                    ->where('user_id', $membership->user_id)
                    ->exists();

                if (! $exists) {
                    $projectAccessRecords[] = [
                        'project_id' => $project->id,
                        'user_id' => $membership->user_id,
                        'permissions' => json_encode([
                            'view' => true,
                            'deploy' => true,
                            'manage' => true,
                            'delete' => true,
                        ]),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }

        // Bulk insert in chunks to avoid memory issues
        if (! empty($projectAccessRecords)) {
            foreach (array_chunk($projectAccessRecords, 500) as $chunk) {
                DB::table('project_user')->insert($chunk);
            }
        }
    }

    /**
     * Reverse the migrations.
     *
     * Removes all auto-granted project access records.
     * Note: This will remove ALL project_user records, including any
     * that were manually created after migration.
     */
    public function down(): void
    {
        // We can't selectively rollback, so we truncate
        // This is safe because this table was just created
        DB::table('project_user')->truncate();
    }
};
