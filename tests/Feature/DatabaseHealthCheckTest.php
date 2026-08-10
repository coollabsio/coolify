<?php

use App\Livewire\Project\Database\Health;
use App\Models\StandalonePostgresql;
use Illuminate\Auth\Access\AuthorizationException;

it('defaults to an enabled healthcheck when nothing is configured', function () {
    $database = new StandalonePostgresql;

    expect($database->isHealthcheckEnabled())->toBeTrue();
});

it('builds the compose healthcheck block from the model timing fields', function () {
    $database = new StandalonePostgresql([
        'health_check_interval' => 30,
        'health_check_timeout' => 7,
        'health_check_retries' => 4,
        'health_check_start_period' => 12,
    ]);

    $config = $database->healthCheckConfiguration(['CMD', 'pg_isready']);

    expect($config)->toBe([
        'test' => ['CMD', 'pg_isready'],
        'interval' => '30s',
        'timeout' => '7s',
        'retries' => 4,
        'start_period' => '12s',
    ]);
});

it('falls back to safe defaults when timing fields are missing', function () {
    $database = new StandalonePostgresql;

    $config = $database->healthCheckConfiguration(['CMD', 'pg_isready']);

    expect($config['interval'])->toBe('15s')
        ->and($config['timeout'])->toBe('5s')
        ->and($config['retries'])->toBe(5)
        ->and($config['start_period'])->toBe('5s');
});

it('reports the healthcheck as disabled when the flag is false', function () {
    $database = new StandalonePostgresql(['health_check_enabled' => false]);

    expect($database->isHealthcheckEnabled())->toBeFalse();
});

it('uses distinct hash fragments for ambiguous healthcheck values', function () {
    $enabledDatabase = new StandalonePostgresql([
        'health_check_enabled' => true,
        'health_check_interval' => 5,
        'health_check_timeout' => 5,
        'health_check_retries' => 5,
        'health_check_start_period' => 5,
    ]);

    $disabledDatabase = new StandalonePostgresql([
        'health_check_enabled' => false,
        'health_check_interval' => 15,
        'health_check_timeout' => 5,
        'health_check_retries' => 5,
        'health_check_start_period' => 5,
    ]);

    $getHashFragment = function () {
        return $this->healthCheckConfigurationHash();
    };

    expect($getHashFragment->call($enabledDatabase))
        ->toBe('1|5|5|5|5')
        ->not->toBe($getHashFragment->call($disabledDatabase))
        ->and($getHashFragment->call($disabledDatabase))->toBe('0|15|5|5|5');
});

it('does not mark configuration changed when health update authorization fails', function () {
    $database = new class
    {
        public ?string $config_hash = null;

        public int $configurationChangedChecks = 0;

        public function isConfigurationChanged(bool $save = false): bool
        {
            $this->configurationChangedChecks++;

            return true;
        }
    };

    $component = new class extends Health
    {
        public array $dispatchedEvents = [];

        public function authorize($ability, $arguments = [])
        {
            throw new AuthorizationException('This action is unauthorized.');
        }

        public function dispatch($event, ...$params)
        {
            $this->dispatchedEvents[] = $event;

            return null;
        }
    };

    $component->database = $database;
    $component->submit();

    expect($database->configurationChangedChecks)->toBe(0)
        ->and($component->dispatchedEvents)->toBe(['error']);
});

it('toggles database healthcheck and marks configuration changed', function () {
    $database = new class
    {
        public ?string $config_hash = 'existing';

        public bool $health_check_enabled = false;

        public int $health_check_interval = 15;

        public int $health_check_timeout = 5;

        public int $health_check_retries = 5;

        public int $health_check_start_period = 5;

        public int $saveCalls = 0;

        public function save(): void
        {
            $this->saveCalls++;
        }
    };

    $component = new class extends Health
    {
        public array $dispatchedEvents = [];

        public function authorize($ability, $arguments = [])
        {
            return true;
        }

        public function dispatch($event, ...$params)
        {
            $this->dispatchedEvents[] = $event;

            return null;
        }

        public function syncData(bool $toModel = false): void
        {
            if ($toModel) {
                $this->database->health_check_enabled = $this->healthCheckEnabled;
                $this->database->save();
            }
        }
    };

    $component->database = $database;
    $component->healthCheckEnabled = false;
    $component->healthCheckInterval = 15;
    $component->healthCheckTimeout = 5;
    $component->healthCheckRetries = 5;
    $component->healthCheckStartPeriod = 5;

    $component->toggleHealthcheck();

    expect($database->health_check_enabled)->toBeTrue()
        ->and($database->saveCalls)->toBe(1)
        ->and($component->dispatchedEvents)->toBe(['success', 'configurationChanged']);
});
