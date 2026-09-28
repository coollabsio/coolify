<?php

use App\Models\LocalPersistentVolume;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Visus\Cuid2\Cuid2;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->stack = seedBrowserResourceStack();
});

/**
 * Creates two volume backup schedules whose DOM source order (newest first)
 * differs from the default "Target A–Z" sort, so a broken sort is visible.
 *
 * @return array{alpha: LocalPersistentVolume, zeta: LocalPersistentVolume}
 */
function createSortableBackupSchedules(mixed $resource): array
{
    $volumes = [];

    foreach (['alpha' => now()->subDays(2), 'zeta' => now()->subDay()] as $prefix => $createdAt) {
        $volume = LocalPersistentVolume::create([
            'uuid' => (string) new Cuid2,
            'name' => $prefix.'-data',
            'mount_path' => '/'.$prefix,
            'resource_id' => $resource->id,
            'resource_type' => $resource->getMorphClass(),
        ]);

        $backup = $volume->scheduledBackups()->create([
            'team_id' => 0,
            'frequency' => 'daily',
        ]);

        // Timestamps are not mass-assignable on the model, so set them explicitly
        // to make "Newest first" and "Oldest first" distinguishable.
        $backup->timestamps = false;
        $backup->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->save();

        $volumes[$prefix] = $volume;
    }

    return $volumes;
}

/**
 * Returns a JS expression that lists the visible backup rows by their vertical
 * position on screen, so the assertion reflects what the user actually sees.
 */
function visibleBackupRowOrderScript(): string
{
    return <<<'JS'
        () => [...document.querySelectorAll('.data-table-row.backup-table-grid')]
            .filter((row) => row.offsetParent !== null)
            .map((row) => ({
                name: row.querySelector('span').textContent.trim(),
                top: row.getBoundingClientRect().top,
            }))
            .sort((left, right) => left.top - right.top)
            .map((row) => row.name)
            .join(',')
        JS;
}

function chooseBackupSort(mixed $page, string $label): mixed
{
    return $page->click('Sort')
        ->wait(0.3)
        ->click($label)
        ->wait(0.3);
}

it('visually reorders application backup schedules when a sort option is chosen', function () {
    $application = createBrowserApplication($this->stack, [
        'uuid' => 'app-browser-backup-sort',
        'name' => 'Backup Sort App',
    ]);
    createSortableBackupSchedules($application);

    loginAndSkipBoarding();

    $url = applicationConfigurationUrl(
        $this->stack['project'],
        $this->stack['environment'],
        $application
    ).'/backups';

    $page = visit($url);
    $page->assertSee('alpha-data')
        ->assertSee('zeta-data')
        ->assertScript(visibleBackupRowOrderScript(), 'alpha-data,zeta-data')
        ->screenshot(filename: 'application-backups-sort-default');

    chooseBackupSort($page, 'Newest first')
        ->assertScript(visibleBackupRowOrderScript(), 'zeta-data,alpha-data')
        ->screenshot(filename: 'application-backups-sort-newest');

    chooseBackupSort($page, 'Oldest first')
        ->assertScript(visibleBackupRowOrderScript(), 'alpha-data,zeta-data')
        ->screenshot(filename: 'application-backups-sort-oldest');

    chooseBackupSort($page, 'Target Z–A')
        ->assertScript(visibleBackupRowOrderScript(), 'zeta-data,alpha-data')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'application-backups-sort-target-desc');
});

it('visually reorders service backup schedules when a sort option is chosen', function () {
    $created = createBrowserService($this->stack, [
        'uuid' => 'svc-browser-backup-sort',
        'name' => 'Backup Sort Service',
    ]);
    createSortableBackupSchedules($created['serviceApplication']);

    loginAndSkipBoarding();

    $url = serviceConfigurationUrl(
        $this->stack['project'],
        $this->stack['environment'],
        $created['service']
    ).'/storage-backups';

    $page = visit($url);
    $page->assertSee('alpha-data')
        ->assertSee('zeta-data')
        ->assertScript(visibleBackupRowOrderScript(), 'alpha-data,zeta-data')
        ->screenshot(filename: 'service-backups-sort-default');

    chooseBackupSort($page, 'Newest first')
        ->assertScript(visibleBackupRowOrderScript(), 'zeta-data,alpha-data')
        ->screenshot(filename: 'service-backups-sort-newest');

    chooseBackupSort($page, 'Oldest first')
        ->assertScript(visibleBackupRowOrderScript(), 'alpha-data,zeta-data')
        ->screenshot(filename: 'service-backups-sort-oldest');

    chooseBackupSort($page, 'Target Z–A')
        ->assertScript(visibleBackupRowOrderScript(), 'zeta-data,alpha-data')
        ->assertNoJavaScriptErrors()
        ->screenshot(filename: 'service-backups-sort-target-desc');
});
