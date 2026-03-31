<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspaces', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('name');
            $table->string('description')->nullable();
            $table->smallInteger('concurrent_builds');
            $table->smallInteger('default_deployment_timeout');
            $table->boolean('is_2fa_required');
            $table->timestamps();
        });
    }
};
