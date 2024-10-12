<?php

use App\Jobs\DeleteResourceJob;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\GithubApp;
use App\Models\Service;
use App\Models\StandaloneDocker;
use Illuminate\Support\Collection;
use Symfony\Component\Yaml\Yaml;

beforeEach(function () {
    $this->applicationYaml = '
version: "3.8"
services:
  app:
    image: nginx
    environment:
      SERVICE_FQDN_APP: /app
      APP_KEY: base64
      APP_DEBUG: "${APP_DEBUG:-false}"
      APP_URL: $SERVICE_FQDN_APP
      DB_URL: postgres://${SERVICE_USER_POSTGRES}:${SERVICE_PASSWORD_POSTGRES}@db:5432/postgres?schema=public
    volumes:
      - "./nginx:/etc/nginx"
      - "data:/var/www/html"
    depends_on:
      - db
  db:
    image: postgres
    environment:
      POSTGRES_USER: "${SERVICE_USER_POSTGRES}"
      POSTGRES_PASSWORD: "${SERVICE_PASSWORD_POSTGRES}"
    volumes:
      - "dbdata:/var/lib/postgresql/data"
    healthcheck:
      test:
        - CMD
        - pg_isready
        - "-U"
        - "postgres"
      interval: 2s
      timeout: 10s
      retries: 10
    depends_on:
      app:
        condition: service_healthy
networks:
  default:
    name: something
    external: true
  noinet:
    driver: bridge
    internal: true';

    $this->applicationComposeFileString = Yaml::parse($this->applicationYaml);

    $this->application = Application::create([
        'name' => 'Application for tests',
        'docker_compose_domains' => json_encode([
            'app' => [
                'domain' => 'http://bcoowoookw0co4cok4sgc4k8.127.0.0.1.sslip.io',
            ],
        ]),
        'preview_url_template' => '{{pr_id}}.{{domain}}',
        'uuid' => 'bcoowoookw0co4cok4sgc4k8s',
        'repository_project_id' => 603035348,
        'git_repository' => 'coollabsio/coolify-examples',
        'git_branch' => 'main',
        'base_directory' => '/docker-compose-test',
        'docker_compose_location' => 'docker-compose.yml',
        'docker_compose_raw' => $this->applicationYaml,
        'build_pack' => 'dockercompose',
        'ports_exposes' => '3000',
        'environment_id' => 1,
        'destination_id' => 0,
        'destination_type' => StandaloneDocker::class,
        'source_id' => 1,
        'source_type' => GithubApp::class,
    ]);
    $this->application->environment_variables_preview()->where('key', 'APP_DEBUG')->update(['value' => 'true']);
    $this->applicationPreview = ApplicationPreview::create([
        'git_type' => 'github',
        'application_id' => $this->application->id,
        'pull_request_id' => 1,
        'pull_request_html_url' => 'https://github.com/coollabsio/coolify-examples/pull/1',
    ]);
    $this->serviceYaml = '
services:
  activepieces:
    image: "ghcr.io/activepieces/activepieces:latest"
    environment:
      - SERVICE_FQDN_ACTIVEPIECES
      - AP_API_KEY=$SERVICE_PASSWORD_64_APIKEY
      - AP_URL=$SERVICE_URL_ACTIVEPIECES
      - AP_ENCRYPTION_KEY=$SERVICE_PASSWORD_ENCRYPTIONKEY
      - AP_ENGINE_EXECUTABLE_PATH=dist/packages/engine/main.js
      - AP_ENVIRONMENT=prod
      - AP_EXECUTION_MODE=${AP_EXECUTION_MODE}
      - AP_FRONTEND_URL=$SERVICE_FQDN_ACTIVEPIECES
      - AP_JWT_SECRET=$SERVICE_PASSWORD_64_JWT
      - AP_POSTGRES_DATABASE=activepieces
      - AP_POSTGRES_HOST=postgres
      - AP_POSTGRES_PASSWORD=$SERVICE_PASSWORD_POSTGRES
      - AP_POSTGRES_PORT=5432
      - AP_POSTGRES_USERNAME=$SERVICE_USER_POSTGRES
      - AP_REDIS_HOST=redis
      - AP_REDIS_PORT=6379
      - AP_SANDBOX_RUN_TIME_SECONDS=600
      - AP_TELEMETRY_ENABLED=true
      - "AP_TEMPLATES_SOURCE_URL=https://cloud.activepieces.com/api/v1/flow-templates"
      - AP_TRIGGER_DEFAULT_POLL_INTERVAL=5
      - AP_WEBHOOK_TIMEOUT_SECONDS=30
    depends_on:
      postgres:
        condition: service_healthy
      redis:
        condition: service_started
    healthcheck:
      test: ["CMD", "curl", "-f", "http://127.0.0.1:80"]
      interval: 5s
      timeout: 20s
      retries: 10
  postgres:
    image: "nginx"
    environment:
      - SERVICE_FQDN_ACTIVEPIECES=/api
      - POSTGRES_DB=activepieces
      - PASSW=$AP_POSTGRES_PASSWORD
      - AP_FRONTEND_URL=$SERVICE_FQDN_ACTIVEPIECES
      - POSTGRES_PASSWORD=$SERVICE_PASSWORD_POSTGRES
      - POSTGRES_USER=$SERVICE_USER_POSTGRES
    volumes:
      - "pg-data:/var/lib/postgresql/data"
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U $${POSTGRES_USER} -d $${POSTGRES_DB}"]
      interval: 5s
      timeout: 20s
      retries: 10
  redis:
    image: "redis:latest"
    volumes:
      - "redis_data:/data"
    healthcheck:
      test: ["CMD", "redis-cli", "ping"]
      interval: 5s
      timeout: 20s
      retries: 10

';

    $this->serviceComposeFileString = Yaml::parse($this->serviceYaml);

    $this->service = Service::create([
        'name' => 'Service for tests',
        'uuid' => 'tgwcg8w4s844wkog8kskw44g',
        'docker_compose_raw' => $this->serviceYaml,
        'environment_id' => 1,
        'server_id' => 0,
        'destination_id' => 0,
        'destination_type' => StandaloneDocker::class,
    ]);
});

afterEach(function () {
    // $this->applicationPreview->forceDelete();
    $this->application->forceDelete();
    DeleteResourceJob::dispatchSync($this->service);
    $this->service->forceDelete();
});

test('ServiceComposeParseNew', function () {
    $output = newParser($this->service);
    $this->service->saveComposeConfigs();
    // ray('New parser');
    // ray($output->toArray());
    ray($this->service->environment_variables->pluck('value', 'key')->toArray());
    expect($output)->toBeInstanceOf(Collection::class);
});

// test('ApplicationComposeParse', function () {
//     expect($this->jsonapplicationComposeFile)->toBeJson()->ray();

//     $output = $this->application->newParser();
//     $outputOld = $this->application->parse();
//     expect($output)->toBeInstanceOf(Collection::class);
//     expect($outputOld)->toBeInstanceOf(Collection::class);

//     $services = $output->get('services');
//     $servicesCount = count($this->applicationComposeFile['services']);
//     expect($services)->toHaveCount($servicesCount);

//     $app = $services->get('app');
//     expect($app)->not->toBeNull();

//     $db = $services->get('db');
//     expect($db)->not->toBeNull();

//     $appDependsOn = $app->get('depends_on');
//     expect($appDependsOn)->toContain('db');

//     $dbDependsOn = $db->get('depends_on');

//     expect($dbDependsOn->keys()->first())->toContain('app');
//     expect(data_get($dbDependsOn, 'app.condition'))->toBe('service_healthy');

//     $environment = $app->get('environment');
//     expect($environment)->not->toBeNull();

//     $coolifyBranch = $environment->get('COOLIFY_BRANCH');
//     expect($coolifyBranch)->toBe('main');

//     $coolifyContainerName = $environment->get('COOLIFY_CONTAINER_NAME');
//     expect($coolifyContainerName)->toMatch('/app-[a-z0-9]{24}-[0-9]{12}/');

//     $volumes = $app->get('volumes');
//     // /etc/nginx
//     $fileMount = $volumes->get(0);
//     $applicationConfigurationDir = application_configuration_dir();
//     expect($fileMount)->toBe("{$applicationConfigurationDir}/{$this->application->uuid}/nginx:/etc/nginx");

//     // data:/var/www/html
//     $volumeMount = $volumes->get(1);
//     expect($volumeMount)->toBe("{$this->application->uuid}_data:/var/www/html");

//     $containerName = $app->get('container_name');
//     expect($containerName)->toMatch('/app-[a-z0-9]{24}-[0-9]{12}/');

//     $labels = $app->get('labels');
//     expect($labels)->not->toBeNull();
//     expect($labels)->toContain('coolify.managed=true');
//     expect($labels)->toContain('coolify.pullRequestId=0');

//     $topLevelVolumes = $output->get('volumes');
//     expect($topLevelVolumes)->not->toBeNull();
//     $firstVolume = $topLevelVolumes->first();
//     expect(data_get($firstVolume, 'name'))->toBe("{$this->application->uuid}_data");

//     $topLevelNetworks = $output->get('networks');
//     expect($topLevelNetworks)->not->toBeNull();
//     $defaultNetwork = data_get($topLevelNetworks, 'default');
//     expect($defaultNetwork)->not->toBeNull();
//     expect(data_get($defaultNetwork, 'name'))->toBe('something');
//     expect(data_get($defaultNetwork, 'external'))->toBe(true);

//     $noinetNetwork = data_get($topLevelNetworks, 'noinet');
//     expect($noinetNetwork)->not->toBeNull();
//     expect(data_get($noinetNetwork, 'driver'))->toBe('bridge');
//     expect(data_get($noinetNetwork, 'internal'))->toBe(true);

//     $serviceNetwork = data_get($topLevelNetworks, "{$this->application->uuid}");
//     expect($serviceNetwork)->not->toBeNull();
//     expect(data_get($serviceNetwork, 'name'))->toBe("{$this->application->uuid}");
//     expect(data_get($serviceNetwork, 'external'))->toBe(true);

// });

// test('ApplicationComposeParsePreviewDeployment', function () {
//     $pullRequestId = 1;
//     $previewId = 77;
//     expect($this->jsonapplicationComposeFile)->toBeJson()->ray();

//     $output = $this->application->newParser(pull_request_id: $pullRequestId, preview_id: $previewId);
//     $outputOld = $this->application->parse();
//     expect($output)->toBeInstanceOf(Collection::class);
//     expect($outputOld)->toBeInstanceOf(Collection::class);

//     ray(Yaml::dump($output->toArray(), 10, 2));
//     $services = $output->get('services');
//     $servicesCount = count($this->applicationComposeFile['services']);
//     expect($services)->toHaveCount($servicesCount);

//     $appNull = $services->get('app');
//     expect($appNull)->toBeNull();

//     $dbNull = $services->get('db');
//     expect($dbNull)->toBeNull();

//     $app = $services->get("app-pr-{$pullRequestId}");
//     expect($app)->not->toBeNull();

//     $db = $services->get("db-pr-{$pullRequestId}");
//     expect($db)->not->toBeNull();

//     $appDependsOn = $app->get('depends_on');
//     expect($appDependsOn)->toContain('db-pr-'.$pullRequestId);

//     $dbDependsOn = $db->get('depends_on');

//     expect($dbDependsOn->keys()->first())->toContain('app-pr-'.$pullRequestId);
//     expect(data_get($dbDependsOn, 'app-pr-'.$pullRequestId.'.condition'))->toBe('service_healthy');

//     $environment = $app->get('environment');
//     expect($environment)->not->toBeNull();

//     $coolifyBranch = $environment->get('COOLIFY_BRANCH');
//     expect($coolifyBranch)->toBe("pull/{$pullRequestId}/head");

//     $coolifyContainerName = $environment->get('COOLIFY_CONTAINER_NAME');
//     expect($coolifyContainerName)->toMatch("/app-[a-z0-9]{24}-pr-{$pullRequestId}/");

//     $volumes = $app->get('volumes');
//     // /etc/nginx
//     $fileMount = $volumes->get(0);
//     $applicationConfigurationDir = application_configuration_dir();
//     expect($fileMount)->toBe("{$applicationConfigurationDir}/{$this->application->uuid}/nginx-pr-{$pullRequestId}:/etc/nginx");

//     // data:/var/www/html
//     $volumeMount = $volumes->get(1);
//     expect($volumeMount)->toBe("{$this->application->uuid}_data-pr-{$pullRequestId}:/var/www/html");

//     $containerName = $app->get('container_name');
//     expect($containerName)->toMatch("/app-[a-z0-9]{24}-pr-{$pullRequestId}/");

//     $labels = $app->get('labels');
//     expect($labels)->not->toBeNull();
//     expect($labels)->toContain('coolify.managed=true');
//     expect($labels)->toContain("coolify.pullRequestId={$pullRequestId}");

//     $topLevelVolumes = $output->get('volumes');
//     expect($topLevelVolumes)->not->toBeNull();
//     $firstVolume = $topLevelVolumes->first();
//     expect(data_get($firstVolume, 'name'))->toBe("{$this->application->uuid}_data-pr-{$pullRequestId}");

//     $topLevelNetworks = $output->get('networks');
//     expect($topLevelNetworks)->not->toBeNull();
//     $defaultNetwork = data_get($topLevelNetworks, 'default');
//     expect($defaultNetwork)->not->toBeNull();
//     expect(data_get($defaultNetwork, 'name'))->toBe('something');
//     expect(data_get($defaultNetwork, 'external'))->toBe(true);

//     $noinetNetwork = data_get($topLevelNetworks, 'noinet');
//     expect($noinetNetwork)->not->toBeNull();
//     expect(data_get($noinetNetwork, 'driver'))->toBe('bridge');
//     expect(data_get($noinetNetwork, 'internal'))->toBe(true);

//     $serviceNetwork = data_get($topLevelNetworks, "{$this->application->uuid}-{$pullRequestId}");
//     expect($serviceNetwork)->not->toBeNull();
//     expect(data_get($serviceNetwork, 'name'))->toBe("{$this->application->uuid}-{$pullRequestId}");
//     expect(data_get($serviceNetwork, 'external'))->toBe(true);

// });

// test('ServiceComposeParseOld', function () {
//     $output = parseDockerComposeFile($this->service);
//     ray('Old parser');
//     // ray($output->toArray());
//     // ray($this->service->environment_variables->pluck('value', 'key')->toArray());
//     // foreach ($this->service->applications as $application) {
//     //     ray($application->persistentStorages->pluck('mount_path', 'name')->toArray());
//     // }
//     // foreach ($this->service->databases as $database) {
//     //     ray($database->persistentStorages->pluck('mount_path', 'name')->toArray());
//     // }
//     expect($output)->toBeInstanceOf(Collection::class);
// });

// test('DockerBinaryAvailableOnLocalhost', function () {
//     $server = Server::find(0);
//     $output = instant_remote_process(['docker --version'], $server);
//     expect($output)->toContain('Docker version');
// });

// test('ConvertComposeEnvironmentToArray', function () {
//     ray()->clearAll();
//     $yaml = '
// services:
//   activepieces:
//     environment:
//       - SERVICE_FQDN_ACTIVEPIECES=/app
//       - AP_API_KEY=$SERVICE_PASSWORD_64_APIKEY
//   activepieces2:
//     environment:
//       - SERVICE_FQDN_ACTIVEPIECES=/v1/realtime
//   postgres:
//     environment:
//       - POSTGRES_DB: activepieces
// ';
//     $parsedYaml = Yaml::parse($yaml);
//     $output = convertComposeEnvironmentToArray($parsedYaml['services']['activepieces']['environment']);
//     $output2 = convertComposeEnvironmentToArray($parsedYaml['services']['activepieces2']['environment']);
//     $dboutput = convertComposeEnvironmentToArray($parsedYaml['services']['postgres']['environment']);
//     ray($output);
//     ray($output2);
//     ray($dboutput);
//     expect($output)->toBeInstanceOf(Collection::class);
//     expect($output2)->toBeInstanceOf(Collection::class);
//     expect($dboutput)->toBeInstanceOf(Collection::class);
// });
