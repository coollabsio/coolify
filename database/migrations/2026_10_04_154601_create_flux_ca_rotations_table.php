<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flux_ca_rotations', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('from_certificate_authority_id')->constrained('flux_certificate_authorities');
            $table->foreignId('to_certificate_authority_id')->constrained('flux_certificate_authorities');
            $table->string('status')->index();
            $table->unsignedInteger('dual_bundle_version');
            $table->unsignedInteger('final_bundle_version')->nullable();
            $table->boolean('switch_forced')->default(false);
            $table->boolean('completion_forced')->default(false);
            $table->text('last_error')->nullable();
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestampTz('switched_at')->nullable();
            $table->timestampTz('retirement_started_at')->nullable();
            $table->timestampTz('cancelled_at')->nullable();
            $table->timestampTz('completed_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flux_ca_rotations');
    }
};
