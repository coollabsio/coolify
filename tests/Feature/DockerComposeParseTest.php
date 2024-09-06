<?php

use App\Actions\Service\DeleteService;
use App\Models\Application;
use App\Models\ApplicationPreview;
use App\Models\GithubApp;
use App\Models\Server;
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
  chatwoot:
    image: chatwoot/chatwoot:latest
    depends_on:
      - postgres
      - redis
    environment:
      - SERVICE_FQDN_CHATWOOT_3000
      - SECRET_KEY_BASE=$SERVICE_PASSWORD_CHATWOOT
      - FRONTEND_URL=${SERVICE_FQDN_CHATWOOT}
      - DEFAULT_LOCALE=${CHATWOOT_DEFAULT_LOCALE}
      - FORCE_SSL=false
      - ENABLE_ACCOUNT_SIGNUP=false
      - REDIS_URL=redis://default@redis:6379
      - REDIS_PASSWORD=$SERVICE_PASSWORD_REDIS
      - REDIS_OPENSSL_VERIFY_MODE=none
      - POSTGRES_DATABASE=chatwoot
      - POSTGRES_HOST=postgres
      - POSTGRES_USERNAME=$SERVICE_USER_POSTGRES_USER
      - POSTGRES_PASSWORD=$SERVICE_PASSWORD_POSTGRES
      - RAILS_MAX_THREADS=5
      - NODE_ENV=production
      - RAILS_ENV=production
      - INSTALLATION_ENV=docker
      - MAILER_SENDER_EMAIL=${CHATWOOT_MAILER_SENDER_EMAIL}
      - SMTP_ADDRESS=${CHATWOOT_SMTP_ADDRESS}
      - SMTP_AUTHENTICATION=${CHATWOOT_SMTP_AUTHENTICATION}
      - SMTP_DOMAIN=${CHATWOOT_SMTP_DOMAIN}
      - SMTP_ENABLE_STARTTLS_AUTO=${CHATWOOT_SMTP_ENABLE_STARTTLS_AUTO}
      - SMTP_PORT=${CHATWOOT_SMTP_PORT}
      - SMTP_USERNAME=${CHATWOOT_SMTP_USERNAME}
      - SMTP_PASSWORD=${CHATWOOT_SMTP_PASSWORD}
      - ACTIVE_STORAGE_SERVICE=local
    entrypoint: docker/entrypoints/rails.sh
    command: sh -c "bundle exec rails db:chatwoot_prepare && bundle exec rails s -p 3000 -b 0.0.0.0"
    volumes:
      - rails-data:/app/storage
    healthcheck:
      test: ["CMD", "wget", "--spider", "-q", "http://127.0.0.1:3000"]
      interval: 5s
      timeout: 20s
      retries: 10

  sidekiq:
    image: chatwoot/chatwoot:latest
    depends_on:
      - postgres
      - redis
    environment:
      - SECRET_KEY_BASE=$SERVICE_PASSWORD_CHATWOOT
      - FRONTEND_URL=${SERVICE_FQDN_CHATWOOT}
      - DEFAULT_LOCALE=${CHATWOOT_DEFAULT_LOCALE}
      - FORCE_SSL=false
      - ENABLE_ACCOUNT_SIGNUP=false
      - REDIS_URL=redis://default@redis:6379
      - REDIS_PASSWORD=$SERVICE_PASSWORD_REDIS
      - REDIS_OPENSSL_VERIFY_MODE=none
      - POSTGRES_DATABASE=chatwoot
      - POSTGRES_HOST=postgres
      - POSTGRES_USERNAME=$SERVICE_USER_POSTGRES_USER
      - POSTGRES_PASSWORD=$SERVICE_PASSWORD_POSTGRES
      - RAILS_MAX_THREADS=5
      - NODE_ENV=production
      - RAILS_ENV=production
      - INSTALLATION_ENV=docker
      - MAILER_SENDER_EMAIL=${CHATWOOT_MAILER_SENDER_EMAIL}
      - SMTP_ADDRESS=${CHATWOOT_SMTP_ADDRESS}
      - SMTP_AUTHENTICATION=${CHATWOOT_SMTP_AUTHENTICATION}
      - SMTP_DOMAIN=${CHATWOOT_SMTP_DOMAIN}
      - SMTP_ENABLE_STARTTLS_AUTO=${CHATWOOT_SMTP_ENABLE_STARTTLS_AUTO}
      - SMTP_PORT=${CHATWOOT_SMTP_PORT}
      - SMTP_USERNAME=${CHATWOOT_SMTP_USERNAME}
      - SMTP_PASSWORD=${CHATWOOT_SMTP_PASSWORD}
      - ACTIVE_STORAGE_SERVICE=local
    command: ["bundle", "exec", "sidekiq", "-C", "config/sidekiq.yml"]
    volumes:
      - sidekiq-data:/app/storage
    healthcheck:
      test: ["CMD-SHELL", "bundle exec rails runner \'puts Sidekiq.redis(&:info)\' > /dev/null 2>&1"]
      interval: 30s
      timeout: 10s
      retries: 3

  postgres:
    image: postgres:12
    restart: always
    volumes:
      - postgres-data:/var/lib/postgresql/data
    environment:
      - POSTGRES_DB=chatwoot
      - POSTGRES_USER=$SERVICE_USER_POSTGRES_USER
      - POSTGRES_PASSWORD=$SERVICE_PASSWORD_POSTGRES
    healthcheck:
      test: ["CMD-SHELL", "pg_isready -U $SERVICE_USER_POSTGRES_USER -d chatwoot -h 127.0.0.1"]
      interval: 30s
      timeout: 10s
      retries: 5

  redis:
    image: redis:alpine
    restart: always
    command: ["sh", "-c", "redis-server --requirepass \"$SERVICE_PASSWORD_REDIS\""]
    volumes:
      - redis-data:/data
    healthcheck:
      test: ["CMD", "redis-cli", "-a", "$SERVICE_PASSWORD_REDIS", "PING"]
      interval: 30s
      timeout: 10s
      retries: 5

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
    DeleteService::run($this->service);
    $this->service->forceDelete();
});

test('ServiceComposeParseNew', function () {
    $output = newParser($this->service);
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
