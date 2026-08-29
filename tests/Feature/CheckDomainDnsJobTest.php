<?php

use App\Actions\Shared\CheckDomainDns;
use App\Jobs\CheckDomainDnsJob;
use App\Models\Application;
use App\Models\Environment;
use App\Models\InstanceSettings;
use App\Models\Project;
use App\Models\Team;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

afterEach(fn () => CheckDomainDns::clearFake());

beforeEach(function () {
    InstanceSettings::unguarded(fn () => InstanceSettings::create([
        'id' => 0,
        'is_dns_validation_enabled' => false,
    ]));

    $team = Team::factory()->create();
    $project = Project::factory()->create(['team_id' => $team->id]);
    $environment = Environment::factory()->create(['project_id' => $project->id]);

    $this->application = Application::factory()->create([
        'environment_id' => $environment->id,
        'destination_id' => 1,
        'destination_type' => 'App\\Models\\StandaloneDocker',
        'fqdn' => 'https://app.example.com',
        'domain_dns_statuses' => [
            'https://app.example.com' => [
                'status' => 'checking',
                'message' => 'Checking DNS...',
                'expected_ip' => null,
                'checked_at' => null,
                'check_id' => 'test-check',
            ],
        ],
    ]);
});

it('persists a skipped result when dns validation is disabled', function () {
    (new CheckDomainDnsJob(
        $this->application,
        'https://app.example.com',
        'https://app.example.com',
        null,
        null,
        'test-check',
    ))->handle();

    $status = $this->application->fresh()->domain_dns_statuses['https://app.example.com'];

    expect($status['status'])->toBe('skipped')
        ->and($status['message'])->toBe('DNS validation is disabled in instance settings.')
        ->and($status['checked_at'])->not->toBeNull();
});

it('does not restore a dns status removed before the job finishes', function () {
    $this->application->update(['domain_dns_statuses' => null]);

    (new CheckDomainDnsJob(
        $this->application,
        'https://app.example.com',
        'https://app.example.com',
        null,
        null,
        'test-check',
    ))->handle();

    expect($this->application->fresh()->domain_dns_statuses)->toBeNull();
});

it('uses the shared dns action', function () {
    CheckDomainDns::shouldRun()
        ->once()
        ->andReturn([
            'https://app.example.com' => [
                'status' => 'ok',
                'message' => 'DNS looks correct.',
                'expected_ip' => null,
                'checked_at' => now()->toIso8601String(),
            ],
        ]);

    (new CheckDomainDnsJob(
        $this->application,
        'https://app.example.com',
        'https://app.example.com',
        null,
        null,
        'test-check',
    ))->handle();

    expect($this->application->fresh()->domain_dns_statuses['https://app.example.com']['status'])->toBe('ok');
});

it('does not let an older job overwrite a newer check for the same domain', function () {
    $oldJob = new CheckDomainDnsJob(
        $this->application,
        'https://app.example.com',
        'https://app.example.com',
        null,
        null,
        'test-check',
    );

    $statuses = $this->application->domain_dns_statuses;
    $statuses['https://app.example.com']['check_id'] = 'newer-check';
    $this->application->update(['domain_dns_statuses' => $statuses]);

    $oldJob->handle();

    $status = $this->application->fresh()->domain_dns_statuses['https://app.example.com'];

    expect($status['status'])->toBe('checking')
        ->and($status['check_id'])->toBe('newer-check');
});
