<?php

use App\Models\Server;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->boolean('is_metrics_enabled')->default(false)->change();
        });
        Server::where('is_metrics_enabled', true)->update(['is_metrics_enabled' => false]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('servers', function (Blueprint $table) {
            $table->boolean('is_metrics_enabled')->default(true)->change();
        });
        Server::where('is_metrics_enabled', false)->update(['is_metrics_enabled' => true]);
    }
};
