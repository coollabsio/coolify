<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flux_certificate_authorities', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->unsignedInteger('version')->unique();
            $table->text('certificate_pem');
            $table->text('private_key_pem');
            $table->string('fingerprint', 64)->unique();
            $table->string('serial_number', 40)->unique();
            $table->timestampTz('valid_from');
            $table->timestampTz('valid_until');
            $table->string('state')->default('active')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flux_certificate_authorities');
    }
};
