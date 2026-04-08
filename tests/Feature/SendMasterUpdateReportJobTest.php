<?php

use App\Jobs\SendMasterUpdateReportJob;
use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\UpdateNotificationReportState;
use App\Models\User;
use App\Notifications\MasterUpdateReport;
use App\Services\Notifications\MasterUpdateReportBuilder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function () {
    InstanceSettings::query()->forceCreate([
        'id' => 0,
        'instance_timezone' => 'America/Chicago',
    ]);
});

afterEach(function () {
    Mockery::close();
});

it('creates master update report settings with weekly monday defaults', function () {
    $team = Team::factory()->create();

    expect($team->emailNotificationSettings->master_update_report_email_notifications)->toBeTrue()
        ->and($team->emailNotificationSettings->master_update_report_frequency)->toBe('weekly')
        ->and($team->emailNotificationSettings->master_update_report_day)->toBe('monday');
});

it('suppresses unchanged report items and only sends changed updates once', function () {
    Notification::fake();

    $team = Team::factory()->create();
    $user = User::factory()->create();
    $team->members()->attach($user);

    $team->emailNotificationSettings()->update([
        'use_instance_email_settings' => true,
        'master_update_report_email_notifications' => true,
        'master_update_report_frequency' => 'daily',
    ]);
    $team->refresh();

    $firstBatch = [
        [
            'section' => 'coolify_upgrades',
            'item_type' => 'coolify_upgrade',
            'item_key' => 'instance:coolify',
            'fingerprint' => hash('sha256', '4.1.1->4.1.2'),
            'label' => 'Coolify',
            'summary' => '4.1.1 -> 4.1.2',
            'url' => 'https://coolify.test/settings/updates',
        ],
        [
            'section' => 'container_image_updates',
            'item_type' => 'container_image_update',
            'item_key' => 'application:10',
            'fingerprint' => hash('sha256', 'redis:7.2.0->redis:7.2.1'),
            'label' => 'Example Project / Redis (Application)',
            'summary' => 'redis:7.2.0 -> redis:7.2.1',
            'url' => 'https://coolify.test/project/example',
        ],
    ];

    $secondBatch = $firstBatch;
    $thirdBatch = [
        [
            'section' => 'coolify_upgrades',
            'item_type' => 'coolify_upgrade',
            'item_key' => 'instance:coolify',
            'fingerprint' => hash('sha256', '4.1.1->4.1.3'),
            'label' => 'Coolify',
            'summary' => '4.1.1 -> 4.1.3',
            'url' => 'https://coolify.test/settings/updates',
        ],
    ];

    $builder = Mockery::mock(MasterUpdateReportBuilder::class);
    $builder->shouldReceive('collect')->times(3)->andReturn($firstBatch, $secondBatch, $thirdBatch);

    $job = new SendMasterUpdateReportJob;

    $job->handle($builder);
    Notification::assertSentTo($team, MasterUpdateReport::class, fn (MasterUpdateReport $notification) => $notification->totalUpdates === 2);
    expect(UpdateNotificationReportState::count())->toBe(2);

    Notification::fake();
    $job->handle($builder);
    Notification::assertNothingSent();
    expect(UpdateNotificationReportState::count())->toBe(2);

    Notification::fake();
    $job->handle($builder);
    Notification::assertSentTo($team, MasterUpdateReport::class, fn (MasterUpdateReport $notification) => $notification->totalUpdates === 1);
    expect(UpdateNotificationReportState::count())->toBe(2)
        ->and(UpdateNotificationReportState::where('item_key', 'instance:coolify')->first()->fingerprint)
        ->toBe(hash('sha256', '4.1.1->4.1.3'));
});
