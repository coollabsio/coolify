<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Rewrites v5 polymorphic rows that stored the Application FQCN (written
 * before the 'v5.application' morph alias existed) so a future class rename
 * cannot orphan them. New rows pick up the alias via getMorphClass().
 */
return new class extends Migration
{
    private const FQCN = 'App\Models\V5\Application';

    private const ALIAS = 'v5.application';

    public function up(): void
    {
        $this->rewriteMorphTypes(self::FQCN, self::ALIAS);
        $this->rewritePairKeys(self::FQCN, self::ALIAS);
    }

    public function down(): void
    {
        $this->rewriteMorphTypes(self::ALIAS, self::FQCN);
        $this->rewritePairKeys(self::ALIAS, self::FQCN);
    }

    private function rewriteMorphTypes(string $from, string $to): void
    {
        if (Schema::hasTable('v5_resource_connections')) {
            foreach (['resource_one_type', 'resource_two_type'] as $column) {
                DB::table('v5_resource_connections')
                    ->where($column, $from)
                    ->update([$column => $to]);
            }
        }

        if (Schema::hasTable('v5_resource_connection_rules')) {
            foreach (['source_resource_type', 'target_resource_type'] as $column) {
                DB::table('v5_resource_connection_rules')
                    ->where($column, $from)
                    ->update([$column => $to]);
            }
        }
    }

    private function rewritePairKeys(string $from, string $to): void
    {
        if (! Schema::hasTable('v5_resource_connections')) {
            return;
        }

        // Backslash escaping in LIKE patterns differs between Postgres and
        // SQLite, so match the FQCN in PHP instead of in SQL.
        DB::table('v5_resource_connections')
            ->select(['id', 'resource_pair_key'])
            ->orderBy('id')
            ->chunkById(100, function ($connections) use ($from, $to): void {
                foreach ($connections as $connection) {
                    if (! str_contains((string) $connection->resource_pair_key, $from)) {
                        continue;
                    }

                    DB::table('v5_resource_connections')
                        ->where('id', $connection->id)
                        ->update([
                            'resource_pair_key' => str_replace($from, $to, $connection->resource_pair_key),
                        ]);
                }
            });
    }
};
