<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_members', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->index()->constrained();
            $table->foreignUlid('user_id')->index()->constrained();
            $table->string('role', 50);
            $table->foreignUlid('custom_role_id')->nullable()->index()->constrained();
            $table->timestamps();

            $table->unique(['workspace_id', 'user_id']);
        });
    }
};
