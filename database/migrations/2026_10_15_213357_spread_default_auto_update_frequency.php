<?php

use App\Models\InstanceSettings;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Move instances that use the old midnight default to a random minute after midnight,
     * so they do not all pull images from the registry at 00:00.
     */
    public function up(): void
    {
        DB::table('instance_settings')
            ->where('auto_update_frequency', '0 0 * * *')
            ->update(['auto_update_frequency' => InstanceSettings::randomAutoUpdateFrequency()]);
    }

    public function down(): void
    {
        //
    }
};
