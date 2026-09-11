<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('flux_certificates', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->foreignId('certificate_authority_id')->constrained('flux_certificate_authorities')->restrictOnDelete();
            $table->unsignedInteger('version')->default(1);
            $table->text('certificate_pem');
            $table->text('private_key_pem');
            $table->string('fingerprint', 64)->unique();
            $table->string('serial_number', 40)->unique();
            $table->json('identities');
            $table->timestampTz('valid_from');
            $table->timestampTz('valid_until')->index();
            $table->string('state')->default('active')->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('flux_certificates');
    }
};
