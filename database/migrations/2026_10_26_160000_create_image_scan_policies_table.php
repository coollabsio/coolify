<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('image_scan_policies', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->string('scope_type')->nullable();
            $table->unsignedBigInteger('scope_id')->nullable();
            $table->string('alert_level')->nullable();
            $table->boolean('gate_enabled')->nullable();
            $table->string('on_scan_error')->nullable();
            $table->timestamps();

            $table->unique(['scope_type', 'scope_id']);
        });

        DB::table('image_scan_policies')->insert([
            'uuid' => new_public_id(),
            'scope_type' => null,
            'scope_id' => null,
            'alert_level' => 'critical',
            'gate_enabled' => false,
            'on_scan_error' => 'deploy',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('image_scan_policies');
    }
};
