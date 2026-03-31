<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_invitations', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('workspace_id')->index()->constrained();
            $table->string('email')->index();
            $table->string('role', 50);
            $table->foreignUlid('custom_role_id')->nullable()->index()->constrained();
            $table->string('via', 50);
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['workspace_id', 'email']);
        });
    }
};
