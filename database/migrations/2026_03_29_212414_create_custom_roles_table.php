<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_roles', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->index()->constrained();
            $table->string('name');
            $table->string('identifier');
            $table->string('description')->nullable();
            $table->jsonb('permissions');
            $table->timestamps();

            $table->unique(['workspace_id', 'identifier']);
        });
    }
};
