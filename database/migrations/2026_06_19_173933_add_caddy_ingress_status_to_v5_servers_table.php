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
        Schema::table('v5_servers', function (Blueprint $table) {
            $table->string('caddy_ingress_status')->nullable()->after('status');
        });

        DB::table('v5_servers')
            ->where('status', 'installed')
            ->where(function ($query) {
                $query
                    ->where('capabilities', 'like', '%"ingress"%')
                    ->orWhere('capabilities', 'like', '%ingress%');
            })
            ->update(['caddy_ingress_status' => 'running']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('v5_servers', function (Blueprint $table) {
            $table->dropColumn('caddy_ingress_status');
        });
    }
};
