<?php

use App\Actions\Server\StopLogDrain;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('stops the Highlight drain container and drops its columns', function () {
    Queue::fake();
    $migration = require database_path('migrations/2026_10_27_160258_remove_highlight_log_drain_from_server_settings_table.php');
    $migration->down();

    $team = Team::factory()->create();
    $highlight = Server::factory()->create(['team_id' => $team->id]);
    $transferred = Server::factory()->create(['team_id' => $team->id, 'server_metadata' => ['transfer' => ['status' => 'transferred']]]);
    Server::factory()->create(['team_id' => $team->id]);
    DB::table('server_settings')->whereIn('server_id', [$highlight->id, $transferred->id])->update(['is_logdrain_highlight_enabled' => true]);

    $migration->up();

    StopLogDrain::assertPushed(1);
    StopLogDrain::assertPushed(fn (StopLogDrain $action, array $arguments) => $arguments[0]->is($highlight));
    expect(Schema::hasColumn('server_settings', 'is_logdrain_highlight_enabled'))->toBeFalse()
        ->and(Schema::hasColumn('server_settings', 'logdrain_highlight_project_id'))->toBeFalse();
});
