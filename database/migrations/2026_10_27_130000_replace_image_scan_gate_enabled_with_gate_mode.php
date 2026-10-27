<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * "Block deploys" gets three modes (spec 15.9): on → strict (closest to the old rule), off → off, inherit stays
     * inherit. The alert level is gone.
     */
    public function up(): void
    {
        Schema::table('image_scan_policies', function (Blueprint $table) {
            $table->string('gate_mode')->nullable()->after('gate_enabled');
        });

        DB::table('image_scan_policies')->where('gate_enabled', true)->update(['gate_mode' => 'strict']);
        DB::table('image_scan_policies')->where('gate_enabled', false)->update(['gate_mode' => 'off']);
        DB::table('image_scan_policies')->whereNull('scope_type')->whereNull('gate_mode')->update(['gate_mode' => 'off']);

        Schema::table('image_scan_policies', function (Blueprint $table) {
            $table->dropColumn(['gate_enabled', 'alert_level']);
        });
    }

    public function down(): void
    {
        Schema::table('image_scan_policies', function (Blueprint $table) {
            $table->string('alert_level')->nullable()->after('scope_id');
            $table->boolean('gate_enabled')->nullable()->after('alert_level');
        });

        DB::table('image_scan_policies')->whereIn('gate_mode', ['on', 'strict'])->update(['gate_enabled' => true]);
        DB::table('image_scan_policies')->where('gate_mode', 'off')->update(['gate_enabled' => false]);
        DB::table('image_scan_policies')->whereNull('scope_type')->update(['alert_level' => 'critical']);

        Schema::table('image_scan_policies', function (Blueprint $table) {
            $table->dropColumn('gate_mode');
        });
    }
};
