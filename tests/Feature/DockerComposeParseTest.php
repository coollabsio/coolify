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
            ],
        ],
        'networks' => [
            'default' => [
                'ipv4_address' => '127.0.0.1',
            ],
        ],
    ];
    $this->composeFileString = Yaml::dump($this->composeFile, 4, 2);
    $this->jsonComposeFile = json_encode($this->composeFile, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);

    $this->application = Application::create([
        'name' => 'Application for tests',
        'fqdn' => 'http://test.com',
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

    expect($this->jsonComposeFile)->toBeJson()->ray();

    $yaml = Yaml::parse($this->jsonComposeFile);
    $output = dockerComposeParserForApplications(
        application: $this->application,
        compose: collect($yaml),
    );
    expect($output)->toBeInstanceOf(Collection::class)->ray();
});

test('DockerBinaryAvailableOnLocalhost', function () {
    $server = Server::find(0);
    $output = instant_remote_process(['docker --version'], $server);
    expect($output)->toContain('Docker version');
});
