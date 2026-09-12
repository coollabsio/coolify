<?php

use App\Models\Server;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Server::query()->where('mode', 'v5-worker')->update(['mode' => 'node-worker']);
        Server::query()->where('mode', 'v5-combined')->update(['mode' => 'node-controller-worker']);
        Server::query()->where('mode', 'v5-control-plane')->update(['mode' => 'node-controller']);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Server::query()->where('mode', 'node-worker')->update(['mode' => 'v5-worker']);
        Server::query()->where('mode', 'node-controller-worker')->update(['mode' => 'v5-combined']);
        Server::query()->where('mode', 'node-controller')->update(['mode' => 'v5-control-plane']);
    }
};
