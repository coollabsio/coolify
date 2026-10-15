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
 * Creates volume backups for bravo, alpha, and charlie, in that order from oldest to newest.
 */
function createSortableVolumeBackups(Model $resource): void
{
    foreach (['bravo', 'alpha', 'charlie'] as $index => $name) {
        $volume = LocalPersistentVolume::create([
            'uuid' => (string) new Cuid2,
            'name' => $name.'-data',
            'mount_path' => '/data/'.$name,
            'resource_id' => $resource->id,
            'resource_type' => $resource->getMorphClass(),
        ]);

        $backup = ScheduledVolumeBackup::create([
            'backupable_type' => $volume->getMorphClass(),
            'backupable_id' => $volume->id,
            'team_id' => 0,
            'frequency' => '0 0 * * *',
            'enabled' => true,
        ]);

        ScheduledVolumeBackup::whereKey($backup->id)->update(['created_at' => now()->subDays(10 - $index)]);
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
        ->click('Sort')
        ->click('Target Z–A')
        ->assertScript(visibleBackupOrderScript(), 'charlie-data,bravo-data,alpha-data')
        ->click('Sort')
        ->click('Oldest first')
        ->assertScript(visibleBackupOrderScript(), 'bravo-data,alpha-data,charlie-data')
        ->click('Sort')
        ->click('Newest first')
        ->assertScript(visibleBackupOrderScript(), 'charlie-data,alpha-data,bravo-data')
        ->screenshot(filename: $screenshot);
}

it('reorders application storage backups when the sort option changes', function () {
    $application = createBrowserApplication($this->stack, [
        'uuid' => 'app-browser-backup-sort',
        'name' => 'Backup Sort App',
    ]);
    createSortableVolumeBackups($application);

    loginAndSkipBoarding();

    $page = visit(applicationConfigurationUrl($this->stack['project'], $this->stack['environment'], $application).'/backups');

    assertBackupSortOrders($page, 'application-backup-sort');
});

it('reorders service storage backups when the sort option changes', function () {
    ['service' => $service, 'serviceApplication' => $serviceApplication] = createBrowserService($this->stack, [
        'uuid' => 'svc-browser-backup-sort',
    ]);
    createSortableVolumeBackups($serviceApplication);

    loginAndSkipBoarding();

    $page = visit(serviceConfigurationUrl($this->stack['project'], $this->stack['environment'], $service).'/storage-backups');

    assertBackupSortOrders($page, 'service-backup-sort');
});
