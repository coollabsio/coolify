<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        // Fillfactor < 100 leaves free space per page so Postgres can do HOT
        // (Heap-Only Tuple) in-place updates instead of allocating a new tuple
        // elsewhere. Coolify's hot-update tables churn rows on every Sentinel
        // push / status change; without page-local headroom, non-HOT updates
        // accumulate dead tuples and bloat the heap (we've seen up to 50× on
        // cloud). Lower fillfactor on hot-update tables, default on the rest.
        DB::statement('ALTER TABLE applications SET (fillfactor = 70)');
        DB::statement('ALTER TABLE servers SET (fillfactor = 85)');
        DB::statement('ALTER TABLE services SET (fillfactor = 85)');
        DB::statement('ALTER TABLE service_applications SET (fillfactor = 85)');
        DB::statement('ALTER TABLE service_databases SET (fillfactor = 85)');
        DB::statement('ALTER TABLE standalone_postgresqls SET (fillfactor = 85)');
        DB::statement('ALTER TABLE standalone_redis SET (fillfactor = 85)');
        DB::statement('ALTER TABLE standalone_mongodbs SET (fillfactor = 85)');
        DB::statement('ALTER TABLE standalone_mysqls SET (fillfactor = 85)');
        DB::statement('ALTER TABLE standalone_mariadbs SET (fillfactor = 85)');
        DB::statement('ALTER TABLE standalone_keydbs SET (fillfactor = 85)');
        DB::statement('ALTER TABLE standalone_dragonflies SET (fillfactor = 85)');
        DB::statement('ALTER TABLE standalone_clickhouses SET (fillfactor = 85)');
        DB::statement('ALTER TABLE application_deployment_queues SET (fillfactor = 90)');

        // Autovacuum default kicks in at 20% dead tuples — too lazy for our
        // churn rate. Trigger at 5% on the highest-write tables to keep heap
        // pages tidy and prevent visibility-map gaps that hurt scan plans.
        DB::statement('ALTER TABLE applications SET (autovacuum_vacuum_scale_factor = 0.05)');
        DB::statement('ALTER TABLE servers SET (autovacuum_vacuum_scale_factor = 0.05)');
        DB::statement('ALTER TABLE service_applications SET (autovacuum_vacuum_scale_factor = 0.05)');
        DB::statement('ALTER TABLE service_databases SET (autovacuum_vacuum_scale_factor = 0.05)');
        DB::statement('ALTER TABLE standalone_postgresqls SET (autovacuum_vacuum_scale_factor = 0.05)');
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE applications RESET (fillfactor, autovacuum_vacuum_scale_factor)');
        DB::statement('ALTER TABLE servers RESET (fillfactor, autovacuum_vacuum_scale_factor)');
        DB::statement('ALTER TABLE services RESET (fillfactor)');
        DB::statement('ALTER TABLE service_applications RESET (fillfactor, autovacuum_vacuum_scale_factor)');
        DB::statement('ALTER TABLE service_databases RESET (fillfactor, autovacuum_vacuum_scale_factor)');
        DB::statement('ALTER TABLE standalone_postgresqls RESET (fillfactor, autovacuum_vacuum_scale_factor)');
        DB::statement('ALTER TABLE standalone_redis RESET (fillfactor)');
        DB::statement('ALTER TABLE standalone_mongodbs RESET (fillfactor)');
        DB::statement('ALTER TABLE standalone_mysqls RESET (fillfactor)');
        DB::statement('ALTER TABLE standalone_mariadbs RESET (fillfactor)');
        DB::statement('ALTER TABLE standalone_keydbs RESET (fillfactor)');
        DB::statement('ALTER TABLE standalone_dragonflies RESET (fillfactor)');
        DB::statement('ALTER TABLE standalone_clickhouses RESET (fillfactor)');
        DB::statement('ALTER TABLE application_deployment_queues RESET (fillfactor)');
    }
};
