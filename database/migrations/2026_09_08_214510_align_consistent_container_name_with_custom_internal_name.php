<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * A stored custom container name was always used when deploying, even with consistent naming off, while
     * the UI only shows the name in consistent naming mode. This migration enables consistent naming for those rows
     * so they keep deploying with their custom name once the flag becomes the only switch.
     */
    public function up(): void
    {
        DB::table('application_settings')
            ->whereNotNull('custom_internal_name')
            ->where('custom_internal_name', '!=', '')
            ->where('is_consistent_container_name_enabled', false)
            ->update(['is_consistent_container_name_enabled' => true]);
    }
};
