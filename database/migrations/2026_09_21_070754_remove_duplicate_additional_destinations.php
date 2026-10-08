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
        DB::table('additional_destinations')
            ->select([
                'application_id',
                'server_id',
                'standalone_docker_id',
                DB::raw('MIN(id) as first_id'),
            ])
            ->groupBy('application_id', 'server_id', 'standalone_docker_id')
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->each(function (object $duplicate): void {
                DB::table('additional_destinations')
                    ->where('application_id', $duplicate->application_id)
                    ->where('server_id', $duplicate->server_id)
                    ->where('standalone_docker_id', $duplicate->standalone_docker_id)
                    ->where('id', '!=', $duplicate->first_id)
                    ->delete();
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void {}
};
