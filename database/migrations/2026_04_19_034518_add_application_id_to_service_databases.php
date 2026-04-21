<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use App\Models\ServiceDatabase;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('service_databases', function (Blueprint $table) {
            $table->dropForeign(['service_id']);
            $table->foreignId('application_id')->nullable()->constrained()->onDelete('cascade');
            $table->foreignId('application_preview_id')->nullable()->constrained()->onDelete('cascade');
        });
        Schema::table('service_databases', function (Blueprint $table) {
            $table->foreignId('service_id')->nullable()->constrained()->onDelete('cascade')->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        ServiceDatabase::query()
            ->whereNull('service_id')
            ->chunkById(100, function ($databases): void {
                $databases->each->forceDelete();
            });
        Schema::table('service_databases', function (Blueprint $table) {
            $table->dropForeign(['application_id']);
            $table->dropColumn('application_id');
            $table->dropForeign(['application_preview_id']);
            $table->dropColumn('application_preview_id');
        });
        Schema::table('service_databases', function (Blueprint $table) {
            $table->dropForeign(['service_id']);
            $table->foreignId('service_id')->nullable(false)->constrained()->onDelete('cascade')->change();
        });
    }
};
