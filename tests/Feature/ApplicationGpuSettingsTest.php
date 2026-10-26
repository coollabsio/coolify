<?php

use App\Livewire\Project\Application\Advanced;
use App\Models\Application;
use App\Models\ApplicationSetting;
use App\Models\Environment;
use App\Models\PrivateKey;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Livewire\Livewire;

uses(RefreshDatabase::class);

function createApplicationForGpuSettingsTest(): Application
{
    $team = Team::factory()->create();
    $team->members()->attach(auth()->id(), ['role' => 'owner']);
    session(['currentTeam' => $team]);
    $server = Server::factory()->create(['team_id' => $team->id]);
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    return Application::create([
        'name' => 'gpu-settings-test-app',
        'git_repository' => 'https://github.com/coollabsio/coolify',
        'git_branch' => 'main',
        'build_pack' => 'nixpacks',
        'ports_exposes' => '3000',
        'environment_id' => $environment->id,
        'destination_id' => $server->standaloneDockers()->firstOrFail()->id,
        'destination_type' => $server->standaloneDockers()->firstOrFail()->getMorphClass(),
    ]);
}

it('builds a valid compose device request from the GPU settings', function (array $settings, array $expected) {
    $setting = new ApplicationSetting(['gpu_driver' => 'nvidia', ...$settings]);

    expect($setting->gpuDeviceReservation())->toBe($expected);
})->with([
    'all gpus' => [[], ['capabilities' => ['gpu'], 'driver' => 'nvidia']],
    'count' => [['gpu_count' => '2'], ['capabilities' => ['gpu'], 'driver' => 'nvidia', 'count' => 2]],
    'count all' => [['gpu_count' => 'all'], ['capabilities' => ['gpu'], 'driver' => 'nvidia', 'count' => 'all']],
    'device ids' => [['gpu_device_ids' => '0, 2,'], ['capabilities' => ['gpu'], 'driver' => 'nvidia', 'device_ids' => ['0', '2']]],
    'count wins over device ids' => [['gpu_count' => '1', 'gpu_device_ids' => '0'], ['capabilities' => ['gpu'], 'driver' => 'nvidia', 'count' => 1]],
    'blank driver' => [['gpu_driver' => ''], ['capabilities' => ['gpu']]],
    'key=value options' => [['gpu_options' => "virtualization=false\nfoo = bar"], ['capabilities' => ['gpu'], 'driver' => 'nvidia', 'options' => ['virtualization' => 'false', 'foo' => 'bar']]],
    'option value with commas' => [['gpu_options' => 'capabilities=compute,utility'], ['capabilities' => ['gpu'], 'driver' => 'nvidia', 'options' => ['capabilities' => 'compute,utility']]],
    'yaml options' => [['gpu_options' => "virtualization: false\nlevel: 2"], ['capabilities' => ['gpu'], 'driver' => 'nvidia', 'options' => ['virtualization' => 'false', 'level' => '2']]],
    'empty options' => [['gpu_options' => ''], ['capabilities' => ['gpu'], 'driver' => 'nvidia']],
    'invalid options are left out' => [['gpu_options' => 'not an option'], ['capabilities' => ['gpu'], 'driver' => 'nvidia']],
]);

describe('advanced GPU form', function () {
    beforeEach(function () {
        $this->actingAs(User::factory()->create());
    });

    it('saves valid GPU options', function () {
        $application = createApplicationForGpuSettingsTest();

        Livewire::test(Advanced::class, ['application' => $application])
            ->set('gpuCount', '2')
            ->set('gpuOptions', 'virtualization=false')
            ->call('submit')
            ->assertDispatched('success');

        $settings = $application->settings()->first();
        expect($settings->gpu_count)->toBe('2')
            ->and($settings->gpu_options)->toBe('virtualization=false');
    });

    it('rejects invalid GPU values', function (string $property, string $value) {
        $application = createApplicationForGpuSettingsTest();

        Livewire::test(Advanced::class, ['application' => $application])
            ->set($property, $value)
            ->call('submit')
            ->assertDispatched('error')
            ->assertNotDispatched('success');
    })->with([
        'count' => ['gpuCount', 'two'],
        'options' => ['gpuOptions', 'not an option'],
    ]);

    it('does not block other settings when a stored GPU value is invalid', function () {
        $application = createApplicationForGpuSettingsTest();
        $application->settings->update(['gpu_options' => 'legacy value', 'gpu_count' => 'some']);

        Livewire::test(Advanced::class, ['application' => $application->fresh()])
            ->set('includeSourceCommitInBuild', true)
            ->call('submit')
            ->assertDispatched('success');

        expect($application->settings()->first()->include_source_commit_in_build)->toBeTrue();
    });

    it('keeps a stored GPU count and device IDs pair when other settings are saved', function () {
        $application = createApplicationForGpuSettingsTest();
        $application->settings->update(['gpu_count' => '1', 'gpu_device_ids' => '0']);

        Livewire::test(Advanced::class, ['application' => $application->fresh()])
            ->set('includeSourceCommitInBuild', true)
            ->call('submit')
            ->assertDispatched('success');

        $settings = $application->settings()->first();
        expect($settings->gpu_count)->toBe('1')
            ->and($settings->gpu_device_ids)->toBe('0')
            ->and($settings->include_source_commit_in_build)->toBeTrue();
    });

    it('refreshes the GPUs of the application server', function () {
        $application = createApplicationForGpuSettingsTest();
        $server = $application->destination->server;
        $server->update(['private_key_id' => PrivateKey::factory()->create(['team_id' => $server->team_id])->id]);
        $server->settings->update(['is_reachable' => true, 'is_usable' => true]);
        Process::fake(['*' => Process::result(output: "---GPUS---\n0, GPU-1a2b, 81559, NVIDIA H100\n---NVIDIA_TOOLKIT---\n/usr/bin/nvidia-ctk")]);

        Livewire::test(Advanced::class, ['application' => $application])
            ->call('refreshGpus')
            ->assertDispatched('success')
            ->assertSee('NVIDIA H100');

        expect($server->fresh()->detectedGpus())->toBe([['index' => '0', 'uuid' => 'GPU-1a2b', 'memory_mb' => 81559, 'name' => 'NVIDIA H100']]);
    });

    it('does not refresh GPUs when no server is validated', function () {
        $application = createApplicationForGpuSettingsTest();
        $application->destination->server->settings->update(['is_reachable' => false, 'is_usable' => false]);
        Process::fake();

        Livewire::test(Advanced::class, ['application' => $application])
            ->call('refreshGpus')
            ->assertDispatched('error')
            ->assertNotDispatched('success');

        Process::assertNothingRan();
    });

    it('does not let a team member refresh GPUs', function () {
        $application = createApplicationForGpuSettingsTest();
        $server = $application->destination->server;
        $server->settings->update(['is_reachable' => true, 'is_usable' => true]);
        $member = User::factory()->create();
        $application->team()->members()->attach($member->id, ['role' => 'member']);
        $this->actingAs($member);
        Process::fake();

        Livewire::test(Advanced::class, ['application' => $application])
            ->call('refreshGpus')
            ->assertNotDispatched('success');

        Process::assertNothingRan();
        expect($server->fresh()->detectedGpus())->toBeNull();
    });

    it('rejects a change that sets both GPU count and device IDs without clearing stored values', function () {
        $application = createApplicationForGpuSettingsTest();
        $application->settings->update(['gpu_device_ids' => '0']);

        Livewire::test(Advanced::class, ['application' => $application->fresh()])
            ->set('gpuCount', '2')
            ->call('submit')
            ->assertDispatched('error')
            ->assertNotDispatched('success');

        $settings = $application->settings()->first();
        expect($settings->gpu_count)->toBeNull()
            ->and($settings->gpu_device_ids)->toBe('0');
    });
});
