<?php

use App\Models\Application;
use App\Models\GithubApp;
use App\Models\Server;
use App\Models\StandaloneDocker;
use Illuminate\Support\Collection;
use Symfony\Component\Yaml\Yaml;

ray()->clearAll();
beforeEach(function () {
    $this->composeFile = [
        'version' => '3.8',
        'services' => [
            'app' => [
                'image' => 'nginx',
                'environment' => [
                    'SERVICE_FQDN_APP' => '/app',
                    'APP_KEY' => 'base64',
                    'APP_DEBUG' => '${APP_DEBUG:-false}',
                    'APP_URL' => '$SERVICE_FQDN_APP',
                ],
                'volumes' => [
                    './:/var/www/html',
                    './nginx:/etc/nginx',
                ],
                'depends_on' => [
                    'db' => [
                        'condition' => 'service_healthy',
                    ],
                ],
            ],
            'db' => [
                'image' => 'postgres',
                'environment' => [
                    'POSTGRES_USER' => 'postgres',
                    'POSTGRES_PASSWORD' => 'postgres',
                ],
                'volumes' => [
                    'dbdata:/var/lib/postgresql/data',
                ],
                'healthcheck' => [
                    'test' => ['CMD', 'pg_isready', '-U', 'postgres'],
                    'interval' => '2s',
                    'timeout' => '10s',
                    'retries' => 10,
                ],

            ],

        ],
        'networks' => [
            'default' => [
                'ipv4_address' => '127.0.0.1',
            ],
        ],
    ];
    $this->composeFileString = Yaml::dump($this->composeFile, 10, 2);
    $this->jsonComposeFile = json_encode($this->composeFile, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

    $this->application = Application::create([
        'name' => 'Application for tests',
        'repository_project_id' => 603035348,
        'git_repository' => 'coollabsio/coolify-examples',
        'git_branch' => 'main',
        'base_directory' => '/docker-compose-test',
        'docker_compose_location' => 'docker-compose.yml',
        'docker_compose_raw' => $this->composeFileString,
        'build_pack' => 'dockercompose',
        'ports_exposes' => '3000',
        'environment_id' => 1,
        'destination_id' => 0,
        'destination_type' => StandaloneDocker::class,
        'source_id' => 1,
        'source_type' => GithubApp::class,
    ]);
});

afterEach(function () {
    $this->application->forceDelete();
});

test('ComposeParse', function () {
    // expect($this->jsonComposeFile)->toBeJson()->ray();

    $output = dockerComposeParserForApplications(
        application: $this->application,
    );
    $outputOld = $this->application->parseCompose();
    expect($output)->toBeInstanceOf(Collection::class)->ray();
    expect($outputOld)->toBeInstanceOf(Collection::class)->ray();

    // Test if image is parsed correctly
    $image = data_get_str($output, 'services.app.image');
    expect($image->value())->toBe('nginx');

    $imageOld = data_get_str($outputOld, 'services.app.image');
    expect($image->value())->toBe($imageOld->value());

    // Test environment variables are parsed correctly
    $environment = data_get_str($output, 'services.app.environment');
    $service_fqdn_app = data_get_str($environment, 'SERVICE_FQDN_APP');

});

test('DockerBinaryAvailableOnLocalhost', function () {
    $server = Server::find(0);
    $output = instant_remote_process(['docker --version'], $server);
    expect($output)->toContain('Docker version');
});
