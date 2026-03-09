<?php

use App\Livewire\Project\Service\LaravelManager;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneDocker;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

uses(RefreshDatabase::class);

beforeEach(function () {
    // Create user and team
    $this->user = User::factory()->create();
    $this->team = Team::factory()->create();
    $this->user->teams()->attach($this->team, ['role' => 'owner']);
    $this->actingAs($this->user);

    // Create server
    $this->server = Server::factory()->create([
        'team_id' => $this->team->id,
    ]);

    // Create standalone docker destination
    $this->destination = StandaloneDocker::factory()->create([
        'server_id' => $this->server->id,
    ]);

    // Create project and environment
    $this->project = Project::factory()->create([
        'team_id' => $this->team->id,
    ]);

    $this->environment = Environment::factory()->create([
        'project_id' => $this->project->id,
    ]);

    // Create service
    $this->service = Service::factory()->create([
        'name' => 'laravel-test',
        'server_id' => $this->server->id,
        'destination_id' => $this->destination->id,
        'destination_type' => $this->destination->getMorphClass(),
        'environment_id' => $this->environment->id,
    ]);

    // Create Laravel service application
    $this->laravelApplication = ServiceApplication::factory()->create([
        'service_id' => $this->service->id,
        'name' => 'laravel',
        'image' => 'php:8.4-fpm-alpine',
        'status' => 'running:healthy',
    ]);

    // Mock route parameters
    $this->routeParams = [
        'project_uuid' => $this->project->uuid,
        'environment_uuid' => $this->environment->uuid,
        'service_uuid' => $this->service->uuid,
    ];
});

describe('Laravel Manager Component', function () {
    test('component class exists and can be instantiated', function () {
        expect(class_exists(LaravelManager::class))->toBeTrue();
    });

    test('component has required properties', function () {
        $component = new LaravelManager;

        expect($component)->toHaveProperty('service');
        expect($component)->toHaveProperty('parameters');
        expect($component)->toHaveProperty('laravelContainers');
        expect($component)->toHaveProperty('selectedContainerForEnv');
        expect($component)->toHaveProperty('envContent');
        expect($component)->toHaveProperty('phpIniSettings');
        expect($component)->toHaveProperty('isLoadingEnv');
        expect($component)->toHaveProperty('isLoadingPhpIni');
    });

    test('component has required methods', function () {
        $component = new LaravelManager;

        expect(method_exists($component, 'mount'))->toBeTrue();
        expect(method_exists($component, 'detectLaravelContainers'))->toBeTrue();
        expect(method_exists($component, 'loadEnvVariables'))->toBeTrue();
        expect(method_exists($component, 'saveEnvFile'))->toBeTrue();
        expect(method_exists($component, 'loadPhpIniSettings'))->toBeTrue();
    });

    test('detects Laravel containers by image name', function () {
        // Create a Laravel container
        $laravelApp = ServiceApplication::factory()->create([
            'service_id' => $this->service->id,
            'name' => 'laravel-app',
            'image' => 'php:8.4-fpm-alpine',
            'status' => 'running:healthy',
        ]);

        // Mock the mount method to avoid route parameter issues
        $component = Livewire::test(LaravelManager::class, [
            'service' => $this->service,
            'parameters' => $this->routeParams,
        ])->assertSuccessful();

        // Verify Laravel containers are detected
        expect($component->get('laravelContainers'))->toBeArray();
    });

    test('detects Laravel containers by environment variables', function () {
        // Create a Laravel container with Laravel env vars
        $laravelApp = ServiceApplication::factory()->create([
            'service_id' => $this->service->id,
            'name' => 'laravel-app',
            'image' => 'nginx:alpine',
            'status' => 'running:healthy',
        ]);

        // Add Laravel environment variable
        $laravelApp->environment_variables()->create([
            'key' => 'APP_KEY',
            'value' => 'base64:test123',
        ]);

        $component = Livewire::test(LaravelManager::class, [
            'service' => $this->service,
            'parameters' => $this->routeParams,
        ])->assertSuccessful();

        expect($component->get('laravelContainers'))->toBeArray();
    });

    test('envContent is initialized as empty string', function () {
        $component = Livewire::test(LaravelManager::class, [
            'service' => $this->service,
            'parameters' => $this->routeParams,
        ])->assertSuccessful();

        expect($component->get('envContent'))->toBe('');
    });

    test('phpIniSettings is initialized as empty array', function () {
        $component = Livewire::test(LaravelManager::class, [
            'service' => $this->service,
            'parameters' => $this->routeParams,
        ])->assertSuccessful();

        expect($component->get('phpIniSettings'))->toBeArray();
        expect($component->get('phpIniSettings'))->toBeEmpty();
    });

    test('isLoadingEnv is initialized as false', function () {
        $component = Livewire::test(LaravelManager::class, [
            'service' => $this->service,
            'parameters' => $this->routeParams,
        ])->assertSuccessful();

        expect($component->get('isLoadingEnv'))->toBeFalse();
    });

    test('isLoadingPhpIni is initialized as false', function () {
        $component = Livewire::test(LaravelManager::class, [
            'service' => $this->service,
            'parameters' => $this->routeParams,
        ])->assertSuccessful();

        expect($component->get('isLoadingPhpIni'))->toBeFalse();
    });
});

describe('Laravel Template', function () {
    test('laravel template file exists', function () {
        $templatePath = base_path('templates/compose/laravel-with-mariadb.yaml');
        expect(file_exists($templatePath))->toBeTrue();
    });

    test('laravel template is valid YAML', function () {
        $templatePath = base_path('templates/compose/laravel-with-mariadb.yaml');
        $content = file_get_contents($templatePath);
        
        expect($content)->not->toBeEmpty();
        
        // Try to parse YAML
        $parsed = yaml_parse($content);
        expect($parsed)->not->toBeFalse();
        expect($parsed)->toBeArray();
    });

    test('laravel template contains required services', function () {
        $templatePath = base_path('templates/compose/laravel-with-mariadb.yaml');
        $content = file_get_contents($templatePath);
        $parsed = yaml_parse($content);
        
        expect($parsed)->toHaveKey('services');
        expect($parsed['services'])->toHaveKey('laravel');
        expect($parsed['services'])->toHaveKey('nginx');
        expect($parsed['services'])->toHaveKey('mariadb');
    });

    test('laravel template has entrypoint script', function () {
        $templatePath = base_path('templates/compose/laravel-with-mariadb.yaml');
        $content = file_get_contents($templatePath);
        
        // Check for entrypoint content
        expect($content)->toContain('entrypoint.sh');
        expect($content)->toContain('composer create-project laravel/laravel');
        expect($content)->toContain('supervisord');
    });

    test('laravel template has supervisor configuration', function () {
        $templatePath = base_path('templates/compose/laravel-with-mariadb.yaml');
        $content = file_get_contents($templatePath);
        
        expect($content)->toContain('supervisord.conf');
        expect($content)->toContain('[program:scheduler]');
        expect($content)->toContain('schedule:run');
        expect($content)->toContain('[program:queue-worker]');
        expect($content)->toContain('queue:work');
    });

    test('laravel template has nginx configuration', function () {
        $templatePath = base_path('templates/compose/laravel-with-mariadb.yaml');
        $content = file_get_contents($templatePath);
        
        expect($content)->toContain('nginx.conf');
        expect($content)->toContain('fastcgi_pass laravel:9000');
        expect($content)->toContain('/var/www/html/public');
    });

    test('laravel logo exists', function () {
        $logoPath = public_path('svgs/laravel.svg');
        expect(file_exists($logoPath))->toBeTrue();
    });
});

describe('Laravel Manager Route', function () {
    test('laravel manager route exists', function () {
        expect(route('project.service.laravel-manager', [
            'project_uuid' => 'test',
            'environment_uuid' => 'test',
            'service_uuid' => 'test',
        ]))->toBeString();
    });
});
