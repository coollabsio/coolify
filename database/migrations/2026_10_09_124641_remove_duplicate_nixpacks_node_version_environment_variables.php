<?php

use App\Models\Application;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Cloned Nixpacks applications got the default NIXPACKS_NODE_VERSION next to the copied one,
     * for production and for preview. Keep the most recently changed variable of each application
     * and preview flag: the latest edit of the user, else the copied value (it has the higher id).
     */
    public function up(): void
    {
        $duplicates = DB::table('environment_variables')
            ->where('resourceable_type', Application::class)
            ->where('key', 'NIXPACKS_NODE_VERSION')
            ->select('resourceable_id', 'is_preview')
            ->groupBy('resourceable_id', 'is_preview')
            ->havingRaw('count(*) > 1')
            ->get();

        foreach ($duplicates as $duplicate) {
            $variables = DB::table('environment_variables')
                ->where('resourceable_type', Application::class)
                ->where('resourceable_id', $duplicate->resourceable_id)
                ->where('is_preview', $duplicate->is_preview)
                ->where('key', 'NIXPACKS_NODE_VERSION')
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
