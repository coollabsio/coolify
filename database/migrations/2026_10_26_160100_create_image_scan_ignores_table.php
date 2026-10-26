<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('image_scan_ignores', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->nullableMorphs('resource');
            $table->string('vulnerability_id', 64)->nullable();
            $table->string('package', 214)->nullable();
            $table->string('reason', 500);
            $table->timestamp('expires_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['team_id', 'resource_type', 'resource_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('image_scan_ignores');
    }
};
