<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use OpenApi\Attributes as OA;
use Spatie\Url\Url;
use Visus\Cuid2\Cuid2;

#[OA\Schema(
    description: 'Service model',
    type: 'object',
    properties: [
        'id' => ['type' => 'integer', 'description' => 'The unique identifier of the service. Only used for database identification.'],
        'uuid' => ['type' => 'string', 'description' => 'The unique identifier of the service.'],
        'name' => ['type' => 'string', 'description' => 'The name of the service.'],
        'environment_id' => ['type' => 'integer', 'description' => 'The unique identifier of the environment where the service is attached to.'],
        'server_id' => ['type' => 'integer', 'description' => 'The unique identifier of the server where the service is running.'],
        'description' => ['type' => 'string', 'description' => 'The description of the service.'],
        'docker_compose_raw' => ['type' => 'string', 'description' => 'The raw docker-compose.yml file of the service.'],
        'docker_compose' => ['type' => 'string', 'description' => 'The docker-compose.yml file that is parsed and modified by Coolify.'],
        'destination_type' => ['type' => 'string', 'description' => 'Destination type.'],
        'destination_id' => ['type' => 'integer', 'description' => 'The unique identifier of the destination where the service is running.'],
        'connect_to_docker_network' => ['type' => 'boolean', 'description' => 'The flag to connect the service to the predefined Docker network.'],
        'is_container_label_escape_enabled' => ['type' => 'boolean', 'description' => 'The flag to enable the container label escape.'],
        'is_container_label_readonly_enabled' => ['type' => 'boolean', 'description' => 'The flag to enable the container label readonly.'],
        'config_hash' => ['type' => 'string', 'description' => 'The hash of the service configuration.'],
        'service_type' => ['type' => 'string', 'description' => 'The type of the service.'],
        'created_at' => ['type' => 'string', 'description' => 'The date and time when the service was created.'],
        'updated_at' => ['type' => 'string', 'description' => 'The date and time when the service was last updated.'],
        'deleted_at' => ['type' => 'string', 'description' => 'The date and time when the service was deleted.'],
    ],
)]
class Service extends BaseModel
{
    use HasFactory, SoftDeletes;

    private static $parserVersion = '4';

    protected $guarded = [];

    protected $appends = ['server_status'];

    protected static function booted()
    {
        static::created(function ($service) {
            $service->compose_parsing_version = self::$parserVersion;
            $service->save();
        });
    }

    public function isConfigurationChanged(bool $save = false)
    {
        $domains = $this->applications()->get()->pluck('fqdn')->sort()->toArray();
        $domains = implode(',', $domains);

        $applicationImages = $this->applications()->get()->pluck('image')->sort();
        $databaseImages = $this->databases()->get()->pluck('image')->sort();
        $images = $applicationImages->merge($databaseImages);
        $images = implode(',', $images->toArray());

        $applicationStorages = $this->applications()->get()->pluck('persistentStorages')->flatten()->sortBy('id');
        $databaseStorages = $this->databases()->get()->pluck('persistentStorages')->flatten()->sortBy('id');
        $storages = $applicationStorages->merge($databaseStorages)->implode('updated_at');

        $newConfigHash = $images.$domains.$images.$storages;
        $newConfigHash .= json_encode($this->environment_variables()->get('value')->sort());
        $newConfigHash = md5($newConfigHash);
        $oldConfigHash = data_get($this, 'config_hash');
        if ($oldConfigHash === null) {
            if ($save) {
                $this->config_hash = $newConfigHash;
                $this->save();
            }

            return true;
        }
        if ($oldConfigHash === $newConfigHash) {
            return false;
        } else {
            if ($save) {
                $this->config_hash = $newConfigHash;
                $this->save();
            }

            return true;
        }
    }

    protected function serverStatus(): Attribute
    {
        return Attribute::make(
            get: function () {
                return $this->server->isFunctional();
            }
        );
    }

    public function isRunning()
    {
        return (bool) str($this->status())->contains('running');
    }

    public function isExited()
    {
        return (bool) str($this->status())->contains('exited');
    }

    public function type()
    {
        return 'service';
    }

    public function project()
    {
        return data_get($this, 'environment.project');
    }

    public function team()
    {
        return data_get($this, 'environment.project.team');
    }

    public function tags()
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    public function getContainersToStop(): array
    {
        $containersToStop = [];
        $applications = $this->applications()->get();
        foreach ($applications as $application) {
            $containersToStop[] = "{$application->name}-{$this->uuid}";
        }
        $dbs = $this->databases()->get();
        foreach ($dbs as $db) {
            $containersToStop[] = "{$db->name}-{$this->uuid}";
        }

        return $containersToStop;
    }

    public function stopContainers(array $containerNames, $server, int $timeout = 300)
    {
        $processes = [];
        foreach ($containerNames as $containerName) {
            $processes[$containerName] = $this->stopContainer($containerName, $timeout);
        }

        $startTime = time();
        while (count($processes) > 0) {
            $finishedProcesses = array_filter($processes, function ($process) {
                return ! $process->running();
            });
            foreach (array_keys($finishedProcesses) as $containerName) {
                unset($processes[$containerName]);
                $this->removeContainer($containerName, $server);
            }

            if (time() - $startTime >= $timeout) {
                $this->forceStopRemainingContainers(array_keys($processes), $server);
                break;
            }

            usleep(100000);
        }
    }

    public function stopContainer(string $containerName, int $timeout): InvokedProcess
    {
        return Process::timeout($timeout)->start("docker stop --time=$timeout $containerName");
    }

    public function removeContainer(string $containerName, $server)
    {
        instant_remote_process(command: ["docker rm -f $containerName"], server: $server, throwError: false);
    }

    public function forceStopRemainingContainers(array $containerNames, $server)
    {
        foreach ($containerNames as $containerName) {
            instant_remote_process(command: ["docker kill $containerName"], server: $server, throwError: false);
            $this->removeContainer($containerName, $server);
        }
    }

    public function delete_configurations()
    {
        $server = data_get($this, 'destination.server');
        $workdir = $this->workdir();
        if (str($workdir)->endsWith($this->uuid)) {
            instant_remote_process(['rm -rf '.$this->workdir()], $server, false);
        }
    }

    public function delete_connected_networks($uuid)
    {
        $server = data_get($this, 'destination.server');
        instant_remote_process(["docker network disconnect {$uuid} coolify-proxy"], $server, false);
        instant_remote_process(["docker network rm {$uuid}"], $server, false);
    }

    public function status()
    {
        $applications = $this->applications;
        $databases = $this->databases;

        $complexStatus = null;
        $complexHealth = null;

        foreach ($applications as $application) {
            if ($application->exclude_from_status) {
                continue;
            }
            $status = str($application->status)->before('(')->trim();
            $health = str($application->status)->between('(', ')')->trim();
            if ($complexStatus === 'degraded') {
                continue;
            }
            if ($status->startsWith('running')) {
                if ($complexStatus === 'exited') {
                    $complexStatus = 'degraded';
                } else {
                    $complexStatus = 'running';
                }
            } elseif ($status->startsWith('restarting')) {
                $complexStatus = 'degraded';
            } elseif ($status->startsWith('exited')) {
                $complexStatus = 'exited';
            }
            if ($health->value() === 'healthy') {
                if ($complexHealth === 'unhealthy') {
                    continue;
                }
                $complexHealth = 'healthy';
            } else {
                $complexHealth = 'unhealthy';
            }
        }
        foreach ($databases as $database) {
            if ($database->exclude_from_status) {
                continue;
            }
            $status = str($database->status)->before('(')->trim();
            $health = str($database->status)->between('(', ')')->trim();
            if ($complexStatus === 'degraded') {
                continue;
            }
            if ($status->startsWith('running')) {
                if ($complexStatus === 'exited') {
                    $complexStatus = 'degraded';
                } else {
                    $complexStatus = 'running';
                }
            } elseif ($status->startsWith('restarting')) {
                $complexStatus = 'degraded';
            } elseif ($status->startsWith('exited')) {
                $complexStatus = 'exited';
            }
            if ($health->value() === 'healthy') {
                if ($complexHealth === 'unhealthy') {
                    continue;
                }
                $complexHealth = 'healthy';
            } else {
                $complexHealth = 'unhealthy';
            }
        }

        return "{$complexStatus}:{$complexHealth}";
    }

    public function extraFields()
    {
        $fields = collect([]);
        $applications = $this->applications()->get();
        foreach ($applications as $application) {
            $image = str($application->image)->before(':');
            if ($image->isEmpty()) {
                continue;
            }
            switch ($image) {
                case $image->contains('castopod'):
                    $data = collect([]);
                    $disable_https = $this->environment_variables()->where('key', 'CP_DISABLE_HTTPS')->first();
                    if ($disable_https) {
                        $data = $data->merge([
                            'Disable HTTPS' => [
                                'key' => 'CP_DISABLE_HTTPS',
                                'value' => data_get($disable_https, 'value'),
                                'rules' => 'required',
                                'customHelper' => "If you want to use https, set this to 0. Variable name: CP_DISABLE_HTTPS",
                            ],
                        ]);
                    }
                    $fields->put('Castopod', $data->toArray());
                    break;
                case $image->contains('label-studio'):
                    $data = collect([]);
                    $username = $this->environment_variables()->where('key', 'LABEL_STUDIO_USERNAME')->first();
                    $password = $this->environment_variables()->where('key', 'SERVICE_PASSWORD_LABELSTUDIO')->first();
                    if ($username) {
                        $data = $data->merge([
                            'Username' => [
                                'key' => 'LABEL_STUDIO_USERNAME',
                                'value' => data_get($username, 'value'),
                                'rules' => 'required',
                            ],
                        ]);
                    }
                    if ($password) {
                        $data = $data->merge([
                            'Password' => [
                                'key' => data_get($password, 'key'),
                                'value' => data_get($password, 'value'),
                                'rules' => 'required',
                                'isPassword' => true,
                            ],
                        ]);
                    }
                    $fields->put('Label Studio', $data->toArray());
                    break;
                case $image->contains('litellm'):
                    $data = collect([]);
                    $username = $this->environment_variables()->where('key', 'SERVICE_USER_UI')->first();
                    $password = $this->environment_variables()->where('key', 'SERVICE_PASSWORD_UI')->first();
                    if ($username) {
                        $data = $data->merge([
                            'Username' => [
                                'key' => data_get($username, 'key'),
                                'value' => data_get($username, 'value'),
                                'rules' => 'required',
                            ],
                        ]);
                    }
                    if ($password) {
                        $data = $data->merge([
                            'Password' => [
                                'key' => data_get($password, 'key'),
                                'value' => data_get($password, 'value'),
                                'rules' => 'required',
                                'isPassword' => true,
                            ],
                        ]);
                    }
                    $fields->put('Litellm', $data->toArray());
                    break;
                case $image->contains('langfuse'):
                    $data = collect([]);
                    $email = $this->environment_variables()->where('key', 'LANGFUSE_INIT_USER_EMAIL')->first();
                    if ($email) {
                        $data = $data->merge([
                            'Admin Email' => [
                                'key' => data_get($email, 'key'),
                                'value' => data_get($email, 'value'),
                                'rules' => 'required|email',
                            ],
                        ]);
                    }
                    $password = $this->environment_variables()->where('key', 'SERVICE_PASSWORD_LANGFUSE')->first();
                    ray('password', $password);
                    if ($password) {
                        $data = $data->merge([
                            'Admin Password' => [
                                'key' => data_get($password, 'key'),
                                'value' => data_get($password, 'value'),
                                'rules' => 'required',
                                'isPassword' => true,
                            ],
                        ]);
                    }
                    $fields->put('Langfuse', $data->toArray());
                    break;
                case $image->contains('invoiceninja'):
                    $data = collect([]);
                    $email = $this->environment_variables()->where('key', 'IN_USER_EMAIL')->first();
                    $data = $data->merge([
                        'Email' => [
                            'key' => data_get($email, 'key'),
                            'value' => data_get($email, 'value'),
                            'rules' => 'required|email',
                        ],
                    ]);
                    $password = $this->environment_variables()->where('key', 'SERVICE_PASSWORD_INVOICENINJAUSER')->first();
                    $data = $data->merge([
                        'Password' => [
                            'key' => data_get($password, 'key'),
                            'value' => data_get($password, 'value'),
                            'rules' => 'required',
                            'isPassword' => true,
                        ],
                    ]);
                    $fields->put('Invoice Ninja', $data->toArray());
                    break;
                case $image->contains('argilla'):
                    $data = collect([]);
                    $api_key = $this->environment_variables()->where('key', 'SERVICE_PASSWORD_APIKEY')->first();
                    $data = $data->merge([
                        'API Key' => [
                            'key' => data_get($api_key, 'key'),
                            'value' => data_get($api_key, 'value'),
                            'isPassword' => true,
                            'rules' => 'required',
                        ],
                    ]);
                    $data = $data->merge([
                        'API Key' => [
                            'key' => data_get($api_key, 'key'),
                            'value' => data_get($api_key, 'value'),
                            'isPassword' => true,
                            'rules' => 'required',
                        ],
                    ]);
                    $username = $this->environment_variables()->where('key', 'ARGILLA_USERNAME')->first();
                    $data = $data->merge([
                        'Username' => [
                            'key' => data_get($username, 'key'),
                            'value' => data_get($username, 'value'),
                            'rules' => 'required',
                        ],
                    ]);
                    $password = $this->environment_variables()->where('key', 'SERVICE_PASSWORD_ARGILLA')->first();
                    $data = $data->merge([
                        'Password' => [
                            'key' => data_get($password, 'key'),
                            'value' => data_get($password, 'value'),
                            'rules' => 'required',
                            'isPassword' => true,
                        ],
                    ]);
                    $fields->put('Argilla', $data->toArray());
                    break;
                case $image->contains('rabbitmq'):
                    $data = collect([]);
                    $host_port = $this->environment_variables()->where('key', 'PORT')->first();
                    $username = $this->environment_variables()->where('key', 'SERVICE_USER_RABBITMQ')->first();
                    $password = $this->environment_variables()->where('key', 'SERVICE_PASSWORD_RABBITMQ')->first();
                    if ($host_port) {
                        $data = $data->merge([
                            'Host Port Binding' => [
                                'key' => data_get($host_port, 'key'),
                                'value' => data_get($host_port, 'value'),
                                'rules' => 'required',
                            ],
                        ]);
                    }
                    if ($username) {
                        $data = $data->merge([
                            'Username' => [
                                'key' => data_get($username, 'key'),
                                'value' => data_get($username, 'value'),
                                'rules' => 'required',
                            ],
                        ]);
                    }
                    if ($password) {
                        $data = $data->merge([
                            'Password' => [
                                'key' => data_get($password, 'key'),
                                'value' => data_get($password, 'value'),
                                'rules' => 'required',
                                'isPassword' => true,
                            ],
                        ]);
                    }
                    $fields->put('RabbitMQ', $data->toArray());
                    break;
                case $image->contains('tolgee'):
                    $data = collect([]);
                    $admin_password = $this->environment_variables()->where('key', 'SERVICE_PASSWORD_TOLGEE')->first();
                    $data = $data->merge([
                        'Admin User' => [
                            'key' => 'TOLGEE_AUTHENTICATION_INITIAL_USERNAME',
                            'value' => 'admin',
                            'readonly' => true,
                            'rules' => 'required',
                        ],
                    ]);
                    if ($admin_password) {
                        $data = $data->merge([
                            'Admin Password' => [
                                'key' => data_get($admin_password, 'key'),
                                'value' => data_get($admin_password, 'value'),
                                'rules' => 'required',
                                'isPassword' => true,
                            ],
                        ]);
                    }
                    $fields->put('Tolgee', $data->toArray());
                    break;
                case $image->contains('logto'):
                    $data = collect([]);
                    $logto_endpoint = $this->environment_variables()->where('key', 'LOGTO_ENDPOINT')->first();
                    $logto_admin_endpoint = $this->environment_variables()->where('key', 'LOGTO_ADMIN_ENDPOINT')->first();
                    if ($logto_endpoint) {
                        $data = $data->merge([
                            'Endpoint' => [
                                'key' => data_get($logto_endpoint, 'key'),
                                'value' => data_get($logto_endpoint, 'value'),
                                'rules' => 'required|url',
                            ],
                        ]);
                    }
                    if ($logto_admin_endpoint) {
                        $data = $data->merge([
                            'Admin Endpoint' => [
                                'key' => data_get($logto_admin_endpoint, 'key'),
                                'value' => data_get($logto_admin_endpoint, 'value'),
                                'rules' => 'required|url',
                            ],
                        ]);
                    }
                    $fields->put('Logto', $data->toArray());
                    break;
                case $image->contains('unleash-server'):
                    $data = collect([]);
                    $admin_password = $this->environment_variables()->where('key', 'SERVICE_PASSWORD_UNLEASH')->first();
                    $data = $data->merge([
                        'Admin User' => [
                            'key' => 'SERVICE_USER_UNLEASH',
                            'value' => 'admin',
                            'readonly' => true,
                            'rules' => 'required',
                        ],
                    ]);
                    if ($admin_password) {
                        $data = $data->merge([
                            'Admin Password' => [
                                'key' => data_get($admin_password, 'key'),
                                'value' => data_get($admin_password, 'value'),
                                'rules' => 'required',
                                'isPassword' => true,
                            ],
                        ]);
                    }
                    $fields->put('Unleash', $data->toArray());
                    break;
                case $image->contains('grafana'):
                    $data = collect([]);
                    $admin_password = $this->environment_variables()->where('key', 'SERVICE_PASSWORD_GRAFANA')->first();
                    $data = $data->merge([
                        'Admin User' => [
                            'key' => 'GF_SECURITY_ADMIN_USER',
                            'value' => 'admin',
                            'readonly' => true,
                            'rules' => 'required',
                        ],
                    ]);
                    if ($admin_password) {
                        $data = $data->merge([
                            'Admin Password' => [
                                'key' => data_get($admin_password, 'key'),
                                'value' => data_get($admin_password, 'value'),
                                'rules' => 'required',
                                'isPassword' => true,
                            ],
                        ]);
                    }
                    $fields->put('Grafana', $data->toArray());
                    break;
                case $image->contains('directus'):
                    $data = collect([]);
                    $admin_email = $this->environment_variables()->where('key', 'ADMIN_EMAIL')->first();
                    $admin_password = $this->environment_variables()->where('key', 'SERVICE_PASSWORD_ADMIN')->first();

                    if ($admin_email) {
                        $data = $data->merge([
                            'Admin Email' => [
                                'key' => data_get($admin_email, 'key'),
                                'value' => data_get($admin_email, 'value'),
                                'rules' => 'required|email',
                            ],
                        ]);
                    }
                    if ($admin_password) {
                        $data = $data->merge([
                            'Admin Password' => [
                                'key' => data_get($admin_password, 'key'),
                                'value' => data_get($admin_password, 'value'),
                                'rules' => 'required',
                                'isPassword' => true,
                            ],
                        ]);
                    }
                    $fields->put('Directus', $data->toArray());
                    break;
                case $image->contains('kong'):
                    $data = collect([]);
                    $dashboard_user = $this->environment_variables()->where('key', 'SERVICE_USER_ADMIN')->first();
                    $dashboard_password = $this->environment_variables()->where('key', 'SERVICE_PASSWORD_ADMIN')->first();
                    if ($dashboard_user) {
                        $data = $data->merge([
                            'Dashboard User' => [
                                'key' => data_get($dashboard_user, 'key'),
                                'value' => data_get($dashboard_user, 'value'),
                                'rules' => 'required',
                            ],
                        ]);
                    }
                    if ($dashboard_password) {
                        $data = $data->merge([
                            'Dashboard Password' => [
                                'key' => data_get($dashboard_password, 'key'),
                                'value' => data_get($dashboard_password, 'value'),
                                'rules' => 'required',
                                'isPassword' => true,
                            ],
                        ]);
                    }
                    $fields->put('Supabase', $data->toArray());
                case $image->contains('minio'):
                    $data = collect([]);
                    $console_url = $this->environment_variables()->where('key', 'MINIO_BROWSER_REDIRECT_URL')->first();
                    $s3_api_url = $this->environment_variables()->where('key', 'MINIO_SERVER_URL')->first();
                    $admin_user = $this->environment_variables()->where('key', 'SERVICE_USER_MINIO')->first();
                    if (is_null($admin_user)) {
                        $admin_user = $this->environment_variables()->where('key', 'MINIO_ROOT_USER')->first();
                    }
                    $admin_password = $this->environment_variables()->where('key', 'SERVICE_PASSWORD_MINIO')->first();
                    if (is_null($admin_password)) {
                        $admin_password = $this->environment_variables()->where('key', 'MINIO_ROOT_PASSWORD')->first();
                    }

                    if ($console_url) {
                        $data = $data->merge([
                            'Console URL' => [
                                'key' => data_get($console_url, 'key'),
                                'value' => data_get($console_url, 'value'),
                                'rules' => 'required|url',
                            ],
                        ]);
                    }
                    if ($s3_api_url) {
                        $data = $data->merge([
                            'S3 API URL' => [
                                'key' => data_get($s3_api_url, 'key'),
                                'value' => data_get($s3_api_url, 'value'),
                                'rules' => 'required|url',
                            ],
                        ]);
                    }
                    if ($admin_user) {
                        $data = $data->merge([
                            'Admin User' => [
                                'key' => data_get($admin_user, 'key'),
                                'value' => data_get($admin_user, 'value'),
                                'rules' => 'required',
                            ],
                        ]);
                    }
                    if ($admin_password) {
                        $data = $data->merge([
                            'Admin Password' => [
                                'key' => data_get($admin_password, 'key'),
                                'value' => data_get($admin_password, 'value'),
                                'rules' => 'required',
                                'isPassword' => true,
                            ],
                        ]);
                    }

                    $fields->put('MinIO', $data->toArray());
                    break;
                case $image->contains('weblate'):
                    $data = collect([]);
                    $admin_email = $this->environment_variables()->where('key', 'WEBLATE_ADMIN_EMAIL')->first();
                    $admin_password = $this->environment_variables()->where('key', 'SERVICE_PASSWORD_WEBLATE')->first();

                    if ($admin_email) {
                        $data = $data->merge([
                            'Admin Email' => [
                                'key' => data_get($admin_email, 'key'),
                                'value' => data_get($admin_email, 'value'),
                                'rules' => 'required|email',
                            ],
                        ]);
                    }
                    if ($admin_password) {
                        $data = $data->merge([
                            'Admin Password' => [
                                'key' => data_get($admin_password, 'key'),
                                'value' => data_get($admin_password, 'value'),
                                'rules' => 'required',
                                'isPassword' => true,
                            ],
                        ]);
                    }
                    $fields->put('Weblate', $data->toArray());
                    break;
                case $image->contains('meilisearch'):
                    $data = collect([]);
                    $SERVICE_PASSWORD_MEILISEARCH = $this->environment_variables()->where('key', 'SERVICE_PASSWORD_MEILISEARCH')->first();
                    if ($SERVICE_PASSWORD_MEILISEARCH) {
                        $data = $data->merge([
                            'API Key' => [
                                'key' => data_get($SERVICE_PASSWORD_MEILISEARCH, 'key'),
                                'value' => data_get($SERVICE_PASSWORD_MEILISEARCH, 'value'),
                                'isPassword' => true,
                            ],
                        ]);
                    }
                    $fields->put('Meilisearch', $data->toArray());
                    break;
                case $image->contains('ghost'):
                    $data = collect([]);
                    $MAIL_OPTIONS_AUTH_PASS = $this->environment_variables()->where('key', 'MAIL_OPTIONS_AUTH_PASS')->first();
                    $MAIL_OPTIONS_AUTH_USER = $this->environment_variables()->where('key', 'MAIL_OPTIONS_AUTH_USER')->first();
                    $MAIL_OPTIONS_SECURE = $this->environment_variables()->where('key', 'MAIL_OPTIONS_SECURE')->first();
                    $MAIL_OPTIONS_PORT = $this->environment_variables()->where('key', 'MAIL_OPTIONS_PORT')->first();
                    $MAIL_OPTIONS_SERVICE = $this->environment_variables()->where('key', 'MAIL_OPTIONS_SERVICE')->first();
                    $MAIL_OPTIONS_HOST = $this->environment_variables()->where('key', 'MAIL_OPTIONS_HOST')->first();
                    if ($MAIL_OPTIONS_AUTH_PASS) {
                        $data = $data->merge([
                            'Mail Password' => [
                                'key' => data_get($MAIL_OPTIONS_AUTH_PASS, 'key'),
                                'value' => data_get($MAIL_OPTIONS_AUTH_PASS, 'value'),
                                'isPassword' => true,
                            ],
                        ]);
                    }
                    if ($MAIL_OPTIONS_AUTH_USER) {
                        $data = $data->merge([
                            'Mail User' => [
                                'key' => data_get($MAIL_OPTIONS_AUTH_USER, 'key'),
                                'value' => data_get($MAIL_OPTIONS_AUTH_USER, 'value'),
                            ],
                        ]);
                    }
                    if ($MAIL_OPTIONS_SECURE) {
                        $data = $data->merge([
                            'Mail Secure' => [
                                'key' => data_get($MAIL_OPTIONS_SECURE, 'key'),
                                'value' => data_get($MAIL_OPTIONS_SECURE, 'value'),
                            ],
                        ]);
                    }
                    if ($MAIL_OPTIONS_PORT) {
                        $data = $data->merge([
                            'Mail Port' => [
                                'key' => data_get($MAIL_OPTIONS_PORT, 'key'),
                                'value' => data_get($MAIL_OPTIONS_PORT, 'value'),
                            ],
                        ]);
                    }
                    if ($MAIL_OPTIONS_SERVICE) {
                        $data = $data->merge([
                            'Mail Service' => [
                                'key' => data_get($MAIL_OPTIONS_SERVICE, 'key'),
                                'value' => data_get($MAIL_OPTIONS_SERVICE, 'value'),
                            ],
                        ]);
                    }
                    if ($MAIL_OPTIONS_HOST) {
                        $data = $data->merge([
                            'Mail Host' => [
                                'key' => data_get($MAIL_OPTIONS_HOST, 'key'),
                                'value' => data_get($MAIL_OPTIONS_HOST, 'value'),
                            ],
                        ]);
                    }

                    $fields->put('Ghost', $data->toArray());
                    break;

                case $image->contains('vaultwarden'):
                    $data = collect([]);

                    $DATABASE_URL = $this->environment_variables()->where('key', 'DATABASE_URL')->first();
                    $ADMIN_TOKEN = $this->environment_variables()->where('key', 'SERVICE_PASSWORD_64_ADMIN')->first();
                    $SIGNUP_ALLOWED = $this->environment_variables()->where('key', 'SIGNUP_ALLOWED')->first();
                    $PUSH_ENABLED = $this->environment_variables()->where('key', 'PUSH_ENABLED')->first();
                    $PUSH_INSTALLATION_ID = $this->environment_variables()->where('key', 'PUSH_SERVICE_ID')->first();
                    $PUSH_INSTALLATION_KEY = $this->environment_variables()->where('key', 'PUSH_SERVICE_KEY')->first();

                    if ($DATABASE_URL) {
                        $data = $data->merge([
                            'Database URL' => [
                                'key' => data_get($DATABASE_URL, 'key'),
                                'value' => data_get($DATABASE_URL, 'value'),
                            ],
                        ]);
                    }
                    if ($ADMIN_TOKEN) {
                        $data = $data->merge([
                            'Admin Password' => [
                                'key' => data_get($ADMIN_TOKEN, 'key'),
                                'value' => data_get($ADMIN_TOKEN, 'value'),
                                'isPassword' => true,
                            ],
                        ]);
                    }
                    if ($SIGNUP_ALLOWED) {
                        $data = $data->merge([
                            'Signup Allowed' => [
                                'key' => data_get($SIGNUP_ALLOWED, 'key'),
                                'value' => data_get($SIGNUP_ALLOWED, 'value'),
                                'rules' => 'required|string|in:true,false',
                            ],
                        ]);
                    }

                    if ($PUSH_ENABLED) {
                        $data = $data->merge([
                            'Push Enabled' => [
                                'key' => data_get($PUSH_ENABLED, 'key'),
                                'value' => data_get($PUSH_ENABLED, 'value'),
                                'rules' => 'required|string|in:true,false',
                            ],
                        ]);
                    }
                    if ($PUSH_INSTALLATION_ID) {
                        $data = $data->merge([
                            'Push Installation ID' => [
                                'key' => data_get($PUSH_INSTALLATION_ID, 'key'),
                                'value' => data_get($PUSH_INSTALLATION_ID, 'value'),
                            ],
                        ]);
                    }
                    if ($PUSH_INSTALLATION_KEY) {
                        $data = $data->merge([
                            'Push Installation Key' => [
                                'key' => data_get($PUSH_INSTALLATION_KEY, 'key'),
                                'value' => data_get($PUSH_INSTALLATION_KEY, 'value'),
                                'isPassword' => true,
                            ],
                        ]);
                    }

                    $fields->put('Vaultwarden', $data);
                    break;
                case $image->contains('gitlab/gitlab'):
                    $password = $this->environment_variables()->where('key', 'SERVICE_PASSWORD_GITLAB')->first();
                    $data = collect([]);
                    if ($password) {
                        $data = $data->merge([
                            'Root Password' => [
                                'key' => data_get($password, 'key'),
                                'value' => data_get($password, 'value'),
                                'rules' => 'required',
                                'isPassword' => true,
                            ],
                        ]);
                    }
                    $data = $data->merge([
                        'Root User' => [
                            'key' => 'GITLAB_ROOT_USER',
                            'value' => 'root',
                            'rules' => 'required',
                            'isPassword' => true,
                        ],
                    ]);

                    $fields->put('GitLab', $data->toArray());
                    break;
                case $image->contains('code-server'):
                    $data = collect([]);
                    $password = $this->environment_variables()->where('key', 'SERVICE_PASSWORD_64_PASSWORDCODESERVER')->first();
                    if ($password) {
                        $data = $data->merge([
                            'Password' => [
                                'key' => data_get($password, 'key'),
                                'value' => data_get($password, 'value'),
                                'rules' => 'required',
                                'isPassword' => true,
                            ],
                        ]);
                    }
                    $sudoPassword = $this->environment_variables()->where('key', 'SERVICE_PASSWORD_SUDOCODESERVER')->first();
                    if ($sudoPassword) {
                        $data = $data->merge([
                            'Sudo Password' => [
                                'key' => data_get($sudoPassword, 'key'),
                                'value' => data_get($sudoPassword, 'value'),
                                'rules' => 'required',
                                'isPassword' => true,
                            ],
                        ]);
                    }
                    $fields->put('Code Server', $data->toArray());
                    break;
                case $image->contains('elestio/strapi'):
                    $data = collect([]);
                    $license = $this->environment_variables()->where('key', 'STRAPI_LICENSE')->first();
                    if ($license) {
                        $data = $data->merge([
                            'License' => [
                                'key' => data_get($license, 'key'),
                                'value' => data_get($license, 'value'),
                            ],
                        ]);
                    }
                    $nodeEnv = $this->environment_variables()->where('key', 'NODE_ENV')->first();
                    if ($nodeEnv) {
                        $data = $data->merge([
                            'Node Environment' => [
                                'key' => data_get($nodeEnv, 'key'),
                                'value' => data_get($nodeEnv, 'value'),
                            ],
                        ]);
                    }

                    $fields->put('Strapi', $data->toArray());
                    break;
                default:
                    $data = collect([]);
                    $admin_user = $this->environment_variables()->where('key', 'SERVICE_USER_ADMIN')->first();
                    // Chaskiq
                    $admin_email = $this->environment_variables()->where('key', 'ADMIN_EMAIL')->first();

                    $admin_password = $this->environment_variables()->where('key', 'SERVICE_PASSWORD_ADMIN')->first();
                    if ($admin_user) {
                        $data = $data->merge([
                            'User' => [
                                'key' => data_get($admin_user, 'key'),
                                'value' => data_get($admin_user, 'value', 'admin'),
                                'readonly' => true,
                                'rules' => 'required',
                            ],
                        ]);
                    }
                    if ($admin_password) {
                        $data = $data->merge([
                            'Password' => [
                                'key' => data_get($admin_password, 'key'),
                                'value' => data_get($admin_password, 'value'),
                                'rules' => 'required',
                                'isPassword' => true,
                            ],
                        ]);
                    }
                    if ($admin_email) {
                        $data = $data->merge([
                            'Email' => [
                                'key' => data_get($admin_email, 'key'),
                                'value' => data_get($admin_email, 'value'),
                                'rules' => 'required|email',
                            ],
                        ]);
                    }
                    $fields->put('Admin', $data->toArray());
                    break;
            }
        }
        $databases = $this->databases()->get();

        foreach ($databases as $database) {
            $image = str($database->image)->before(':');
            if ($image->isEmpty()) {
                continue;
            }
            switch ($image) {
                case $image->contains('postgres'):
                    $userVariables = ['SERVICE_USER_POSTGRES', 'SERVICE_USER_POSTGRESQL'];
                    $passwordVariables = ['SERVICE_PASSWORD_POSTGRES', 'SERVICE_PASSWORD_POSTGRESQL'];
                    $dbNameVariables = ['POSTGRESQL_DATABASE', 'POSTGRES_DB'];
                    $postgres_user = $this->environment_variables()->whereIn('key', $userVariables)->first();
                    $postgres_password = $this->environment_variables()->whereIn('key', $passwordVariables)->first();
                    $postgres_db_name = $this->environment_variables()->whereIn('key', $dbNameVariables)->first();
                    $data = collect([]);
                    if ($postgres_user) {
                        $data = $data->merge([
                            'User' => [
                                'key' => data_get($postgres_user, 'key'),
                                'value' => data_get($postgres_user, 'value'),
                                'rules' => 'required',
                            ],
                        ]);
                    }
                    if ($postgres_password) {
                        $data = $data->merge([
                            'Password' => [
                                'key' => data_get($postgres_password, 'key'),
                                'value' => data_get($postgres_password, 'value'),
                                'rules' => 'required',
                                'isPassword' => true,
                            ],
                        ]);
                    }
                    if ($postgres_db_name) {
                        $data = $data->merge([
                            'Database Name' => [
                                'key' => data_get($postgres_db_name, 'key'),
                                'value' => data_get($postgres_db_name, 'value'),
                                'rules' => 'required',
                            ],
                        ]);
                    }
                    $fields->put('PostgreSQL', $data->toArray());
                    break;
                case $image->contains('mysql'):
                    $userVariables = ['SERVICE_USER_MYSQL', 'SERVICE_USER_WORDPRESS', 'MYSQL_USER'];
                    $passwordVariables = ['SERVICE_PASSWORD_MYSQL', 'SERVICE_PASSWORD_WORDPRESS', 'MYSQL_PASSWORD','SERVICE_PASSWORD_64_MYSQL'];
                    $rootPasswordVariables = ['SERVICE_PASSWORD_MYSQLROOT', 'SERVICE_PASSWORD_ROOT','SERVICE_PASSWORD_64_MYSQLROOT'];
                    $dbNameVariables = ['MYSQL_DATABASE'];
                    $mysql_user = $this->environment_variables()->whereIn('key', $userVariables)->first();
                    $mysql_password = $this->environment_variables()->whereIn('key', $passwordVariables)->first();
                    $mysql_root_password = $this->environment_variables()->whereIn('key', $rootPasswordVariables)->first();
                    $mysql_db_name = $this->environment_variables()->whereIn('key', $dbNameVariables)->first();
                    $data = collect([]);
                    if ($mysql_user) {
                        $data = $data->merge([
                            'User' => [
                                'key' => data_get($mysql_user, 'key'),
                                'value' => data_get($mysql_user, 'value'),
                                'rules' => 'required',
                            ],
                        ]);
                    }
                    if ($mysql_password) {
                        $data = $data->merge([
                            'Password' => [
                                'key' => data_get($mysql_password, 'key'),
                                'value' => data_get($mysql_password, 'value'),
                                'rules' => 'required',
                                'isPassword' => true,
                            ],
                        ]);
                    }
                    if ($mysql_root_password) {
                        $data = $data->merge([
                            'Root Password' => [
                                'key' => data_get($mysql_root_password, 'key'),
                                'value' => data_get($mysql_root_password, 'value'),
                                'rules' => 'required',
                                'isPassword' => true,
                            ],
                        ]);
                    }
                    if ($mysql_db_name) {
                        $data = $data->merge([
                            'Database Name' => [
                                'key' => data_get($mysql_db_name, 'key'),
                                'value' => data_get($mysql_db_name, 'value'),
                                'rules' => 'required',
                            ],
                        ]);
                    }
                    $fields->put('MySQL', $data->toArray());
                    break;
                case $image->contains('mariadb'):
                    $userVariables = ['SERVICE_USER_MARIADB', 'SERVICE_USER_WORDPRESS', '_APP_DB_USER', 'SERVICE_USER_MYSQL', 'MYSQL_USER'];
                    $passwordVariables = ['SERVICE_PASSWORD_MARIADB', 'SERVICE_PASSWORD_WORDPRESS', '_APP_DB_PASS', 'MYSQL_PASSWORD'];
                    $rootPasswordVariables = ['SERVICE_PASSWORD_MARIADBROOT', 'SERVICE_PASSWORD_ROOT', '_APP_DB_ROOT_PASS', 'MYSQL_ROOT_PASSWORD'];
                    $dbNameVariables = ['SERVICE_DATABASE_MARIADB', 'SERVICE_DATABASE_WORDPRESS', '_APP_DB_SCHEMA', 'MYSQL_DATABASE'];
                    $mariadb_user = $this->environment_variables()->whereIn('key', $userVariables)->first();
                    $mariadb_password = $this->environment_variables()->whereIn('key', $passwordVariables)->first();
                    $mariadb_root_password = $this->environment_variables()->whereIn('key', $rootPasswordVariables)->first();
                    $mariadb_db_name = $this->environment_variables()->whereIn('key', $dbNameVariables)->first();
                    $data = collect([]);

                    if ($mariadb_user) {
                        $data = $data->merge([
                            'User' => [
                                'key' => data_get($mariadb_user, 'key'),
                                'value' => data_get($mariadb_user, 'value'),
                                'rules' => 'required',
                            ],
                        ]);
                    }
                    if ($mariadb_password) {
                        $data = $data->merge([
                            'Password' => [
                                'key' => data_get($mariadb_password, 'key'),
                                'value' => data_get($mariadb_password, 'value'),
                                'rules' => 'required',
                                'isPassword' => true,
                            ],
                        ]);
                    }
                    if ($mariadb_root_password) {
                        $data = $data->merge([
                            'Root Password' => [
                                'key' => data_get($mariadb_root_password, 'key'),
                                'value' => data_get($mariadb_root_password, 'value'),
                                'rules' => 'required',
                                'isPassword' => true,
                            ],
                        ]);
                    }
                    if ($mariadb_db_name) {
                        $data = $data->merge([
                            'Database Name' => [
                                'key' => data_get($mariadb_db_name, 'key'),
                                'value' => data_get($mariadb_db_name, 'value'),
                                'rules' => 'required',
                            ],
                        ]);
                    }
                    $fields->put('MariaDB', $data->toArray());
                    break;

            }
        }

        return $fields;
    }

    public function saveExtraFields($fields)
    {
        foreach ($fields as $field) {
            $key = data_get($field, 'key');
            $value = data_get($field, 'value');
            ray($key, $value);
            $found = $this->environment_variables()->where('key', $key)->first();
            if ($found) {
                $found->value = $value;
                $found->save();
            } else {
                $this->environment_variables()->create([
                    'key' => $key,
                    'value' => $value,
                    'is_build_time' => false,
                    'service_id' => $this->id,
                    'is_preview' => false,
                ]);
            }
        }
    }

    public function link()
    {
        if (data_get($this, 'environment.project.uuid')) {
            return route('project.service.configuration', [
                'project_uuid' => data_get($this, 'environment.project.uuid'),
                'environment_name' => data_get($this, 'environment.name'),
                'service_uuid' => data_get($this, 'uuid'),
            ]);
        }

        return null;
    }

    public function failedTaskLink($task_uuid)
    {
        if (data_get($this, 'environment.project.uuid')) {
            $route = route('project.service.scheduled-tasks', [
                'project_uuid' => data_get($this, 'environment.project.uuid'),
                'environment_name' => data_get($this, 'environment.name'),
                'service_uuid' => data_get($this, 'uuid'),
                'task_uuid' => $task_uuid,
            ]);
            $settings = InstanceSettings::get();
            if (data_get($settings, 'fqdn')) {
                $url = Url::fromString($route);
                $url = $url->withPort(null);
                $fqdn = data_get($settings, 'fqdn');
                $fqdn = str_replace(['http://', 'https://'], '', $fqdn);
                $url = $url->withHost($fqdn);

                return $url->__toString();
            }

            return $route;
        }

        return null;
    }

    public function documentation()
    {
        $services = get_service_templates();
        $service = data_get($services, str($this->name)->beforeLast('-')->value, []);

        return data_get($service, 'documentation', config('constants.docs.base_url'));
    }

    public function applications()
    {
        return $this->hasMany(ServiceApplication::class);
    }

    public function databases()
    {
        return $this->hasMany(ServiceDatabase::class);
    }

    public function destination()
    {
        return $this->morphTo();
    }

    public function environment()
    {
        return $this->belongsTo(Environment::class);
    }

    public function server()
    {
        return $this->belongsTo(Server::class);
    }

    public function byUuid(string $uuid)
    {
        $app = $this->applications()->whereUuid($uuid)->first();
        if ($app) {
            return $app;
        }
        $db = $this->databases()->whereUuid($uuid)->first();
        if ($db) {
            return $db;
        }

        return null;
    }

    public function byName(string $name)
    {
        $app = $this->applications()->whereName($name)->first();
        if ($app) {
            return $app;
        }
        $db = $this->databases()->whereName($name)->first();
        if ($db) {
            return $db;
        }

        return null;
    }

    public function scheduled_tasks(): HasMany
    {
        return $this->hasMany(ScheduledTask::class)->orderBy('name', 'asc');
    }

    public function environment_variables(): HasMany
    {

        return $this->hasMany(EnvironmentVariable::class)->orderByRaw("LOWER(key) LIKE LOWER('SERVICE%') DESC, LOWER(key) ASC");
    }

    public function environment_variables_preview(): HasMany
    {
        return $this->hasMany(EnvironmentVariable::class)->where('is_preview', true)->orderByRaw("LOWER(key) LIKE LOWER('SERVICE%') DESC, LOWER(key) ASC");
    }

    public function workdir()
    {
        return service_configuration_dir()."/{$this->uuid}";
    }

    public function saveComposeConfigs()
    {
        $workdir = $this->workdir();

        instant_remote_process([
            "mkdir -p $workdir",
            "cd $workdir",
        ], $this->server);

        $filename = new Cuid2.'-docker-compose.yml';
        Storage::disk('local')->put("tmp/{$filename}", $this->docker_compose);
        $path = Storage::path("tmp/{$filename}");
        instant_scp($path, "{$workdir}/docker-compose.yml", $this->server);
        Storage::disk('local')->delete("tmp/{$filename}");

        $commands[] = "cd $workdir";
        $commands[] = 'rm -f .env || true';

        $envs_from_coolify = $this->environment_variables()->get();
        $sorted = $envs_from_coolify->sortBy(function ($env) {
            if (str($env->key)->startsWith('SERVICE_')) {
                return 1;
            }
            if (str($env->value)->startsWith('$SERVICE_') || str($env->value)->startsWith('${SERVICE_')) {
                return 2;
            }

            return 3;
        });
        foreach ($sorted as $env) {
            if (version_compare($env->version, '4.0.0-beta.347', '<=')) {
                $commands[] = "echo '{$env->key}={$env->real_value}' >> .env";
            } else {
                $real_value = $env->real_value;
                if ($env->version === '4.0.0-beta.239') {
                    $real_value = $env->real_value;
                } else {
                    if ($env->is_literal || $env->is_multiline) {
                        $real_value = '\''.$real_value.'\'';
                    } else {
                        $real_value = escapeEnvVariables($env->real_value);
                    }
                }
                $commands[] = "echo \"{$env->key}={$real_value}\" >> .env";
            }
        }
        if ($sorted->count() === 0) {
            $commands[] = 'touch .env';
        }
        instant_remote_process($commands, $this->server);
    }

    public function parse(bool $isNew = false): Collection
    {
        if ((int) $this->compose_parsing_version >= 3) {
            return newParser($this);
        } elseif ($this->docker_compose_raw) {
            return parseDockerComposeFile($this, $isNew);
        } else {
            return collect([]);
        }

    }

    public function networks()
    {
        $networks = getTopLevelNetworks($this);

        return $networks;
    }
}
