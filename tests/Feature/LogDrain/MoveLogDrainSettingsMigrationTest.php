<?php

use App\Enums\LogDrainType;
use App\Models\Server;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->migration = require database_path('migrations/2026_11_10_120000_move_log_drain_settings_to_type_and_config.php');
    $this->migration->down();

    $team = Team::factory()->create();
    $this->server = Server::factory()->create(['team_id' => $team->id]);
    $this->emptyServer = Server::factory()->create(['team_id' => $team->id]);
    DB::table('server_settings')->where('server_id', $this->server->id)->update([
        'is_logdrain_axiom_enabled' => true,
        'logdrain_axiom_api_key' => 'axiom-key',
        'logdrain_axiom_dataset_name' => 'dataset',
        'is_logdrain_cloudwatch_enabled' => true,
        'logdrain_cloudwatch_region' => 'eu-central-1',
        'logdrain_custom_config' => Crypt::encryptString("[OUTPUT]\n    Name stdout"),
    ]);
});

it('moves the flags and columns of each log drain to the type and the config', function () {
    $this->migration->up();
    $this->migration->up();

    $settings = $this->server->settings->fresh();
    expect($settings->logdrain_type)->toBe(LogDrainType::Axiom)
        ->and($settings->logdrain_config)->toBe([
            'axiom' => ['api_key' => 'axiom-key', 'dataset_name' => 'dataset'],
            'custom' => ['config' => "[OUTPUT]\n    Name stdout"],
            'cloudwatch' => ['region' => 'eu-central-1'],
        ])
        ->and($this->emptyServer->settings->fresh()->logdrain_type)->toBeNull()
        ->and($this->emptyServer->settings->fresh()->logdrain_config)->toBeNull()
        ->and(Schema::hasColumn('server_settings', 'is_logdrain_axiom_enabled'))->toBeFalse()
        ->and(Schema::hasColumn('server_settings', 'logdrain_custom_config'))->toBeFalse();
});

it('restores the old columns on rollback', function () {
    $this->migration->up();
    $this->migration->down();

    $row = DB::table('server_settings')->where('server_id', $this->server->id)->first();
    expect((bool) $row->is_logdrain_axiom_enabled)->toBeTrue()
        ->and((bool) $row->is_logdrain_cloudwatch_enabled)->toBeFalse()
        ->and($row->logdrain_axiom_api_key)->toBe('axiom-key')
        ->and($row->logdrain_cloudwatch_region)->toBe('eu-central-1')
        ->and(Crypt::decryptString($row->logdrain_custom_config))->toBe("[OUTPUT]\n    Name stdout")
        ->and(Schema::hasColumn('server_settings', 'logdrain_type'))->toBeFalse();

    $this->migration->up();
});
