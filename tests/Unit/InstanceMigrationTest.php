<?php

use App\Actions\Migration\CollectResourceVolumes;
use App\Actions\Migration\DetectIndependentCoolifyInstall;
use App\Actions\Migration\ReassignDestinationsOnRemoteInstance;
use App\Actions\Migration\SyncAllVolumesToServer;
use App\Enums\InstanceMigrationStatus;
use Illuminate\Database\Eloquent\Model;

test('instance migration status terminal detection', function () {
    expect(InstanceMigrationStatus::Completed->isTerminal())->toBeTrue()
        ->and(InstanceMigrationStatus::Failed->isTerminal())->toBeTrue()
        ->and(InstanceMigrationStatus::Packaging->isTerminal())->toBeFalse()
        ->and(InstanceMigrationStatus::Consolidating->isTerminal())->toBeFalse()
        ->and(InstanceMigrationStatus::Installing->label())->toBe('Installing Coolify')
        ->and(InstanceMigrationStatus::Restoring->label())->toBe('Restoring Coolify database')
        ->and(InstanceMigrationStatus::progressSteps())->toHaveCount(7)
        ->and(InstanceMigrationStatus::progressSteps()[2])->toBe(InstanceMigrationStatus::Restoring)
        ->and(InstanceMigrationStatus::progressSteps()[3])->toBe(InstanceMigrationStatus::SyncingVolumes);
});

test('managed proxy only host is not an independent coolify install', function () {
    expect(DetectIndependentCoolifyInstall::isIndependentInstall("coolify-proxy\ncoolify-sentinel"))->toBeFalse();
});

test('full coolify stack is an independent install', function () {
    expect(DetectIndependentCoolifyInstall::isIndependentInstall("coolify\ncoolify-db\ncoolify-redis"))->toBeTrue();
});

test('collect resource volumes returns empty for unknown model', function () {
    $resource = new class extends Model
    {
        public $uuid = 'x';

        public function type(): string
        {
            return 'unknown';
        }
    };

    expect(CollectResourceVolumes::run($resource))->toBe([]);
});

test('destination reassignment uses psql on coolify-db instead of artisan tinker', function () {
    $sql = ReassignDestinationsOnRemoteInstance::reassignSql();
    $commands = implode("\n", ReassignDestinationsOnRemoteInstance::reassignCommands());

    expect($sql)
        ->toContain('standalone_dockers')
        ->toContain('server_id = 0')
        ->toContain('UPDATE applications')
        ->toContain('WHERE id <> 0')
        ->toContain("SELECT 'ok'");

    expect($commands)
        ->toContain('docker cp')
        ->toContain('psql')
        ->toContain('coolify-db')
        ->not->toContain('artisan tinker')
        ->not->toContain('docker exec -i');
});

test('instance volume sync skips coolify control-plane volume names', function () {
    expect(SyncAllVolumesToServer::isReservedVolumeName('coolify-db'))->toBeTrue()
        ->and(SyncAllVolumesToServer::isReservedVolumeName('coolify-redis'))->toBeTrue()
        ->and(SyncAllVolumesToServer::isReservedVolumeName('postgres-data-abc123'))->toBeFalse();
});
