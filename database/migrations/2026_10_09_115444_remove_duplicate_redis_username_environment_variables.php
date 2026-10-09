<?php

use App\Models\StandaloneRedis;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Redis databases created or cloned in the UI or API since v4.4.0 got two REDIS_USERNAME variables.
     * Older Docker Compose versions (for example v2.22) refuse to start the container, and newer ones
     * (v2.29 and later) use the last entry. Each start writes the value of one of them to all of them, so values differ only after
     * an edit since the last start.
     * Keep the most recently changed variable of each database, which is the latest edit of the user.
     */
    public function up(): void
    {
        $duplicatedDatabaseIds = DB::table('environment_variables')
            ->where('resourceable_type', StandaloneRedis::class)
            ->where('key', 'REDIS_USERNAME')
            ->groupBy('resourceable_id')
            ->havingRaw('count(*) > 1')
            ->pluck('resourceable_id');

        foreach ($duplicatedDatabaseIds as $databaseId) {
            $variables = DB::table('environment_variables')
                ->where('resourceable_type', StandaloneRedis::class)
                ->where('resourceable_id', $databaseId)
                ->where('key', 'REDIS_USERNAME')
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->pluck('id');

            DB::table('environment_variables')->whereIn('id', $variables->slice(1)->all())->delete();
        }
    }

    public function down(): void
    {
        //
    }
};
