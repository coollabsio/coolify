<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Extends service_databases to support Application-type Docker Compose deployments.
 *
 * Previously every ServiceDatabase row required a service_id (Service parent).
 * Application buildpacks that use Docker Compose also contain database services,
 * which are parented by an Application, not a Service. This migration adds an
 * application_id FK and makes service_id nullable so both parent types coexist —
 * enforced by a CHECK constraint: exactly one of service_id/application_id must be set.
 *
 * Rollback: rows introduced by this migration have no service_id. They are deleted
 * before the NOT NULL constraint is restored. This is intentional — there is no
 * valid service_id to back-fill them with, and they only exist due to up().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('service_databases', function (Blueprint $table) {
            $table->foreignId('application_id')->nullable()->constrained()->nullOnDelete()->after('service_id');
            $table->unsignedBigInteger('service_id')->nullable()->change();
        });

        // Enforce exactly one parent: service_id XOR application_id must be set.
        DB::statement('
            ALTER TABLE service_databases
            ADD CONSTRAINT chk_service_databases_single_parent
            CHECK (
                (service_id IS NOT NULL AND application_id IS NULL) OR
                (service_id IS NULL AND application_id IS NOT NULL)
            )
        ');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE service_databases DROP CONSTRAINT chk_service_databases_single_parent');

        // Rows with a NULL service_id cannot satisfy the restored NOT NULL constraint.
        // These are the application-linked rows introduced by up(). Deleting them is
        // the only correct rollback — there is no valid service_id to assign.
        DB::table('service_databases')->whereNull('service_id')->delete();

        Schema::table('service_databases', function (Blueprint $table) {
            $table->dropForeign(['application_id']);
            $table->dropColumn('application_id');
            $table->unsignedBigInteger('service_id')->nullable(false)->change();
        });
    }
};
