<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        DB::table('teams')
            ->select('teams.id')
            ->whereNotExists(function ($query): void {
                $query->selectRaw('1')
                    ->from('team_user as owners')
                    ->whereColumn('owners.team_id', 'teams.id')
                    ->where('owners.role', 'owner');
            })
            ->orderBy('teams.id')
            ->chunkById(100, function ($teams): void {
                foreach ($teams as $team) {
                    $firstMember = DB::table('team_user')
                        ->where('team_id', $team->id)
                        ->orderByRaw("CASE WHEN role = 'admin' THEN 0 ELSE 1 END")
                        ->orderBy('created_at')
                        ->orderBy('id')
                        ->first();

                    if ($firstMember === null) {
                        continue;
                    }

                    DB::table('team_user')
                        ->where('id', $firstMember->id)
                        ->update([
                            'role' => 'owner',
                            'updated_at' => now(),
                        ]);
                }
            }, 'teams.id', 'id');
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // This data migration cannot identify which owners were promoted safely.
    }
};
