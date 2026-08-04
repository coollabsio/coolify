<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        $this->deleteDuplicateEnvironmentVariables();

        Schema::table('environment_variables', function (Blueprint $table) {
            $table->unique(
                ['resourceable_type', 'resourceable_id', 'key', 'is_preview'],
                'env_vars_resourceable_key_preview_unique'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('environment_variables', function (Blueprint $table) {
            $table->dropUnique('env_vars_resourceable_key_preview_unique');
        });
    }

    /**
     * The unique constraint on (resourceable_type, resourceable_id, key, is_preview)
     * was lost when the table was migrated to polymorphic columns. For each group of
     * duplicate rows, keep the most recently updated one (tiebreak: highest id).
     */
    private function deleteDuplicateEnvironmentVariables(): void
    {
        $duplicateGroups = DB::table('environment_variables')
            ->select('resourceable_type', 'resourceable_id', 'key', 'is_preview')
            ->groupBy('resourceable_type', 'resourceable_id', 'key', 'is_preview')
            ->havingRaw('COUNT(*) > 1')
            ->get();

        foreach ($duplicateGroups as $group) {
            $query = DB::table('environment_variables')->where('key', $group->key);

            foreach (['resourceable_type', 'resourceable_id', 'is_preview'] as $column) {
                if ($group->$column === null) {
                    $query->whereNull($column);
                } else {
                    $query->where($column, $group->$column);
                }
            }

            $idsToDelete = $query
                ->orderByDesc('updated_at')
                ->orderByDesc('id')
                ->pluck('id')
                ->slice(1);

            foreach ($idsToDelete->chunk(500) as $chunk) {
                DB::table('environment_variables')->whereIn('id', $chunk->all())->delete();
            }
        }
    }
};
