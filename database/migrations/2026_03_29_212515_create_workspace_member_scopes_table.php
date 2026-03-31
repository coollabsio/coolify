<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workspace_member_scopes', function (Blueprint $table): void {
            $table->foreignUlid('workspace_member_id')->constrained();
            $table->ulidMorphs('scopeable');

            $table->primary(['workspace_member_id', 'scopeable_type', 'scopeable_id']);
        });
    }
};
