<?php

use App\Models\LocalPersistentVolume;
use App\Models\ScheduledVolumeBackup;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Visus\Cuid2\Cuid2;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->stack = seedBrowserResourceStack();
});

/**
 * Creates volume backups for bravo, alpha, and charlie.
 */
function createSortableVolumeBackups(Model $resource): void
{
    foreach (['bravo', 'alpha', 'charlie'] as $name) {
        $volume = LocalPersistentVolume::create([
            'uuid' => (string) new Cuid2,
            'name' => $name.'-data',
            'mount_path' => '/data/'.$name,
            'resource_id' => $resource->id,
            'resource_type' => $resource->getMorphClass(),
        ]);

        ScheduledVolumeBackup::create([
            'backupable_type' => $volume->getMorphClass(),
            'backupable_id' => $volume->id,
            'team_id' => 0,
            'frequency' => '0 0 * * *',
            'enabled' => true,
        ]);
    }
}

/**
 * Returns the backup target names in the order the browser shows them, top to bottom.
 */
function visibleBackupOrderScript(): string
{
    return <<<'JS'
        () => [...document.querySelectorAll('.data-table-row')]
            .filter((row) => row.offsetParent !== null)
            .sort((left, right) => left.getBoundingClientRect().top - right.getBoundingClientRect().top)
            .map((row) => row.querySelector('span').textContent.trim())
            .join(',')
        JS;
}

function assertBackupSortOrders(mixed $page, string $screenshot): void
{
    $page->assertSee('alpha-data')
        ->assertScript(visibleBackupOrderScript(), 'alpha-data,bravo-data,charlie-data')
        ->click('[aria-label^="Sort by target"]')
        ->assertScript(visibleBackupOrderScript(), 'charlie-data,bravo-data,alpha-data')
        ->screenshot(filename: $screenshot);
}

it('reorders application storage backups when the target header is clicked', function () {
    $application = createBrowserApplication($this->stack, [
        'uuid' => 'app-browser-backup-sort',
        'name' => 'Backup Sort App',
    ]);
    createSortableVolumeBackups($application);

    loginAndSkipBoarding();

    $page = visit(applicationConfigurationUrl($this->stack['project'], $this->stack['environment'], $application).'/backups');

    assertBackupSortOrders($page, 'application-backup-sort');
});

it('reorders service storage backups when the target header is clicked', function () {
    ['service' => $service, 'serviceApplication' => $serviceApplication] = createBrowserService($this->stack, [
        'uuid' => 'svc-browser-backup-sort',
    ]);
    createSortableVolumeBackups($serviceApplication);

    loginAndSkipBoarding();

    $page = visit(serviceConfigurationUrl($this->stack['project'], $this->stack['environment'], $service).'/storage-backups');

    assertBackupSortOrders($page, 'service-backup-sort');
});
