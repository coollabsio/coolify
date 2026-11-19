<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Deployments that failed or were cancelled outside of the deployment job (timeout, stopped worker, cancel)
     * had no end time. The last log entry updates the deployment, so updated_at is close to the end time.
     */
    public function up(): void
    {
        DB::table('application_deployment_queues')
            ->whereNull('finished_at')
            ->whereIn('status', ['finished', 'failed', 'cancelled-by-user', 'skipped'])
            ->update(['finished_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        // The filled end times cannot be told apart from recorded ones.
    }
};
