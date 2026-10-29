<?php

use App\Actions\Server\StopLogDrain;
use App\Models\Server;
use App\Models\ServerSetting;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * Highlight.io ended its hosted service on 2026-02-28, so its log drain can no longer deliver logs.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('server_settings', 'is_logdrain_highlight_enabled')) {
            return;
        }

        try {
            $serverIds = ServerSetting::query()->where('is_logdrain_highlight_enabled', true)->pluck('server_id');
            Server::query()->whereIn('id', $serverIds)->get()
                ->reject(fn (Server $server) => $server->isTransferredAway())
                ->each(fn (Server $server) => StopLogDrain::dispatch($server));
        } catch (Throwable $e) {
            Log::warning('Could not queue the removal of Highlight log drain containers: '.$e->getMessage());
        }

        Schema::table('server_settings', function (Blueprint $table) {
            $table->dropColumn(['is_logdrain_highlight_enabled', 'logdrain_highlight_project_id']);
        });
    }

    public function down(): void
    {
        Schema::table('server_settings', function (Blueprint $table) {
            $table->boolean('is_logdrain_highlight_enabled')->default(false);
            $table->string('logdrain_highlight_project_id')->nullable();
        });
    }
};
