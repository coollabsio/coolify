<?php

namespace App\Models;

use App\Enums\ProxyTypes;
use App\Jobs\ServerFilesFromServerJob;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use OpenApi\Attributes as OA;
use Spatie\Url\Url;
use Symfony\Component\Yaml\Yaml;

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

    protected $guarded = [];

    protected $appends = ['server_status'];

    protected static function booted()
    {
        static::created(function ($service) {
            $service->compose_parsing_version = '2';
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

    public function delete_configurations()
    {
        $server = data_get($this, 'server');
        $workdir = $this->workdir();
        if (str($workdir)->endsWith($this->uuid)) {
            instant_remote_process(['rm -rf '.$this->workdir()], $server, false);
        }
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
            $image = str($application->image)->before(':')->value();
            switch ($image) {
                case str($image)?->contains('rabbitmq'):
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
                case str($image)?->contains('tolgee'):
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
                                'key' => 'SERVICE_PASSWORD_TOLGEE',
                                'value' => data_get($admin_password, 'value'),
                                'rules' => 'required',
                                'isPassword' => true,
                            ],
                        ]);
                    }
                    $fields->put('Tolgee', $data->toArray());
                    break;
                case str($image)?->contains('logto'):
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
                case str($image)?->contains('unleash-server'):
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
                                'key' => 'SERVICE_PASSWORD_UNLEASH',
                                'value' => data_get($admin_password, 'value'),
                                'rules' => 'required',
                                'isPassword' => true,
                            ],
                        ]);
                    }
                    $fields->put('Unleash', $data->toArray());
                    break;
                case str($image)?->contains('grafana'):
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
                                'key' => 'GF_SECURITY_ADMIN_PASSWORD',
                                'value' => data_get($admin_password, 'value'),
                                'rules' => 'required',
                                'isPassword' => true,
                            ],
                        ]);
                    }
                    $fields->put('Grafana', $data->toArray());
                    break;
                case str($image)?->contains('directus'):
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
                case str($image)?->contains('kong'):
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
                case str($image)?->contains('minio'):
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
                case str($image)?->contains('weblate'):
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
                case str($image)?->contains('meilisearch'):
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
                case str($image)?->contains('ghost'):
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
                default:
                    $data = collect([]);
                    $admin_user = $this->environment_variables()->where('key', 'SERVICE_USER_ADMIN')->first();
                    // Chaskiq
                    $admin_email = $this->environment_variables()->where('key', 'ADMIN_EMAIL')->first();

                    $admin_password = $this->environment_variables()->where('key', 'SERVICE_PASSWORD_ADMIN')->first();
                    if ($admin_user) {
                        $data = $data->merge([
                            'User' => [
                                'key' => 'SERVICE_USER_ADMIN',
                                'value' => data_get($admin_user, 'value', 'admin'),
                                'readonly' => true,
                                'rules' => 'required',
                            ],
                        ]);
                    }
                    if ($admin_password) {
                        $data = $data->merge([
                            'Password' => [
                                'key' => 'SERVICE_PASSWORD_ADMIN',
                                'value' => data_get($admin_password, 'value'),
                                'rules' => 'required',
                                'isPassword' => true,
                            ],
                        ]);
                    }
                    if ($admin_email) {
                        $data = $data->merge([
                            'Email' => [
                                'key' => 'ADMIN_EMAIL',
                                'value' => data_get($admin_email, 'value'),
                                'rules' => 'required|email',
                            ],
                        ]);
                    }
                    $fields->put('Admin', $data->toArray());
                    break;
                case str($image)?->contains('vaultwarden'):
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
                case str($image)->contains('gitlab/gitlab'):
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
                            'key' => 'N/A',
                            'value' => 'root',
                            'rules' => 'required',
                            'isPassword' => true,
                        ],
                    ]);

                    $fields->put('GitLab', $data->toArray());
                    break;
            }
        }
        $databases = $this->databases()->get();

        foreach ($databases as $database) {
            $image = str($database->image)->before(':')->value();
            switch ($image) {
                case str($image)->contains('postgres'):
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
                case str($image)->contains('mysql'):
                    $userVariables = ['SERVICE_USER_MYSQL', 'SERVICE_USER_WORDPRESS'];
                    $passwordVariables = ['SERVICE_PASSWORD_MYSQL', 'SERVICE_PASSWORD_WORDPRESS'];
                    $rootPasswordVariables = ['SERVICE_PASSWORD_MYSQLROOT', 'SERVICE_PASSWORD_ROOT'];
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
                case str($image)->contains('mariadb'):
                    $userVariables = ['SERVICE_USER_MARIADB', 'SERVICE_USER_WORDPRESS', '_APP_DB_USER'];
                    $passwordVariables = ['SERVICE_PASSWORD_MARIADB', 'SERVICE_PASSWORD_WORDPRESS', '_APP_DB_PASS'];
                    $rootPasswordVariables = ['SERVICE_PASSWORD_MARIADBROOT', 'SERVICE_PASSWORD_ROOT', '_APP_DB_ROOT_PASS'];
                    $dbNameVariables = ['SERVICE_DATABASE_MARIADB', 'SERVICE_DATABASE_WORDPRESS', '_APP_DB_SCHEMA'];
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
        return $this->hasMany(EnvironmentVariable::class)->orderBy('key', 'asc');
    }

    public function environment_variables_preview(): HasMany
    {
        return $this->hasMany(EnvironmentVariable::class)->where('is_preview', true)->orderBy('key', 'asc');
    }

    public function workdir()
    {
        return service_configuration_dir()."/{$this->uuid}";
    }

    public function saveComposeConfigs()
    {
        $workdir = $this->workdir();
        $commands[] = "mkdir -p $workdir";
        $commands[] = "cd $workdir";

        $json = Yaml::parse($this->docker_compose);
        $this->docker_compose = Yaml::dump($json, 10, 2, Yaml::DUMP_MULTI_LINE_LITERAL_BLOCK);
        $docker_compose_base64 = base64_encode($this->docker_compose);

        $commands[] = "echo $docker_compose_base64 | base64 -d | tee docker-compose.yml > /dev/null";
        $commands[] = 'rm -f .env || true';

        $envs_from_coolify = $this->environment_variables()->get();
        foreach ($envs_from_coolify as $env) {
            $commands[] = "echo '{$env->key}={$env->real_value}' >> .env";
        }
        if ($envs_from_coolify->count() === 0) {
            $commands[] = 'touch .env';
        }
        instant_remote_process($commands, $this->server);
    }

    public function newParser()
    {
        return newParser($this);

        $uuid = data_get($this, 'uuid');
        $server = data_get($this, 'destination.server');
        $compose = data_get($this, 'docker_compose_raw');
        try {
            $yaml = Yaml::parse($compose);
        } catch (\Exception $e) {
            return;
        }
        $allServices = get_service_templates();
        $services = data_get($yaml, 'services', collect([]));
        $topLevel = collect([
            'volumes' => collect(data_get($yaml, 'volumes', [])),
            'networks' => collect(data_get($yaml, 'networks', [])),
            'configs' => collect(data_get($yaml, 'configs', [])),
            'secrets' => collect(data_get($yaml, 'secrets', [])),
        ]);
        // If there are predefined volumes, make sure they are not null
        if ($topLevel->get('volumes')->count() > 0) {
            $temp = collect([]);
            foreach ($topLevel['volumes'] as $volumeName => $volume) {
                if (is_null($volume)) {
                    continue;
                }
                $temp->put($volumeName, $volume);
            }
            $topLevel['volumes'] = $temp;
        }
        // Get the base docker network
        $baseNetwork = collect([$uuid]);
        $parsedServices = collect([]);

        // Let's loop through the services
        foreach ($services as $serviceName => $service) {
            if ($serviceName === 'registry') {
                $tempServiceName = 'docker-registry';
            } else {
                $tempServiceName = $serviceName;
            }
            if (str(data_get($service, 'image'))->contains('glitchtip')) {
                $tempServiceName = 'glitchtip';
            }
            if ($serviceName === 'supabase-kong') {
                $tempServiceName = 'supabase';
            }
            $serviceDefinition = data_get($allServices, $tempServiceName);
            $predefinedPort = data_get($serviceDefinition, 'port');
            if ($serviceName === 'plausible') {
                $predefinedPort = '8000';
            }
            $image = data_get_str($service, 'image');
            $restart = data_get_str($service, 'restart', RESTART_MODE);
            $logging = data_get($service, 'logging');

            if ($server->isLogDrainEnabled() && $this->isLogDrainEnabled()) {
                $logging = [
                    'driver' => 'fluentd',
                    'options' => [
                        'fluentd-address' => 'tcp://127.0.0.1:24224',
                        'fluentd-async' => 'true',
                        'fluentd-sub-second-precision' => 'true',
                    ],
                ];
            }

            $volumes = collect(data_get($service, 'volumes', []));
            $networks = collect(data_get($service, 'networks', []));
            $labels = collect(data_get($service, 'labels', []));
            $environment = collect(data_get($service, 'environment', []));
            $buildArgs = collect(data_get($service, 'build.args', []));
            $environment = $environment->merge($buildArgs);
            $hasHostNetworkMode = data_get($service, 'network_mode') === 'host' ? true : false;

            $containerName = "$serviceName-{$this->uuid}";
            $isDatabase = isDatabaseImage(data_get_str($service, 'image'));
            $volumesParsed = collect([]);

            if ($isDatabase) {
                $savedService = ServiceDatabase::firstOrCreate([
                    'name' => $serviceName,
                    'image' => $image,
                    'service_id' => $this->id,
                ]);
            } else {
                $savedService = ServiceApplication::firstOrCreate([
                    'name' => $serviceName,
                    'image' => $image,
                    'service_id' => $this->id,
                ]);
            }
            $fileStorages = $savedService->fileStorages();

            // Check if image changed
            if ($savedService->image !== $image) {
                $savedService->image = $image;
                $savedService->save();
            }
            if ($volumes->count() > 0) {
                foreach ($volumes as $index => $volume) {
                    $type = null;
                    $source = null;
                    $target = null;
                    $content = null;
                    $isDirectory = false;
                    if (is_string($volume)) {
                        $source = str($volume)->before(':');
                        $target = str($volume)->after(':')->beforeLast(':');
                        $foundConfig = $fileStorages->whereMountPath($target)->first();
                        if (sourceIsLocal($source)) {
                            $type = str('bind');
                            if ($foundConfig) {
                                $contentNotNull_temp = data_get($foundConfig, 'content');
                                if ($contentNotNull_temp) {
                                    $content = $contentNotNull_temp;
                                }
                                $isDirectory = data_get($foundConfig, 'is_directory');
                            } else {
                                // By default, we cannot determine if the bind is a directory or not, so we set it to directory
                                $isDirectory = true;
                            }
                        } else {
                            $type = str('volume');
                        }
                    } elseif (is_array($volume)) {
                        $type = data_get_str($volume, 'type');
                        $source = data_get_str($volume, 'source');
                        $target = data_get_str($volume, 'target');
                        $content = data_get($volume, 'content');
                        $isDirectory = (bool) data_get($volume, 'isDirectory', null) || (bool) data_get($volume, 'is_directory', null);

                        $foundConfig = $fileStorages->whereMountPath($target)->first();
                        if ($foundConfig) {
                            $contentNotNull_temp = data_get($foundConfig, 'content');
                            if ($contentNotNull_temp) {
                                $content = $contentNotNull_temp;
                            }
                            $isDirectory = data_get($foundConfig, 'is_directory');
                        } else {
                            // if isDirectory is not set (or false) & content is also not set, we assume it is a directory
                            if ((is_null($isDirectory) || ! $isDirectory) && is_null($content)) {
                                $isDirectory = true;
                            }
                        }
                    }
                    if ($type->value() === 'bind') {
                        if ($source->value() === '/var/run/docker.sock') {
                            return $volume;
                        }
                        if ($source->value() === '/tmp' || $source->value() === '/tmp/') {
                            return $volume;
                        }
                        $mainDirectory = str(base_configuration_dir().'/applications/'.$uuid);
                        $source = replaceLocalSource($source, $mainDirectory);

                        LocalFileVolume::updateOrCreate(
                            [
                                'mount_path' => $target,
                                'resource_id' => $savedService->id,
                                'resource_type' => get_class($savedService),
                            ],
                            [
                                'fs_path' => $source,
                                'mount_path' => $target,
                                'content' => $content,
                                'is_directory' => $isDirectory,
                                'resource_id' => $savedService->id,
                                'resource_type' => get_class($savedService),
                            ]
                        );
                        $volume = "$source:$target";
                    } elseif ($type->value() === 'volume') {
                        if ($topLevel->get('volumes')->has($source->value())) {
                            $temp = $topLevel->get('volumes')->get($source->value());
                            if (data_get($temp, 'driver_opts.type') === 'cifs') {
                                return $volume;
                            }
                            if (data_get($temp, 'driver_opts.type') === 'nfs') {
                                return $volume;
                            }
                        }
                        $slugWithoutUuid = Str::slug($source, '-');
                        $name = "{$uuid}_{$slugWithoutUuid}";
                        if (is_string($volume)) {
                            $source = str($volume)->before(':');
                            $target = str($volume)->after(':')->beforeLast(':');
                            $source = $name;
                            $volume = "$source:$target";
                        } elseif (is_array($volume)) {
                            data_set($volume, 'source', $name);
                        }
                        $topLevel->get('volumes')->put($name, [
                            'name' => $name,
                        ]);

                        LocalPersistentVolume::updateOrCreate(
                            [
                                'mount_path' => $target,
                                'resource_id' => $savedService->id,
                                'resource_type' => get_class($savedService),
                            ],
                            [
                                'name' => $name,
                                'mount_path' => $target,
                                'resource_id' => $savedService->id,
                                'resource_type' => get_class($savedService),
                            ]
                        );
                    }
                    dispatch(new ServerFilesFromServerJob($savedService));
                    $volumesParsed->put($index, $volume);
                }
            }
            if ($topLevel->get('networks')?->count() > 0) {
                foreach ($topLevel->get('networks') as $networkName => $network) {
                    if ($networkName === 'default') {
                        continue;
                    }
                    // ignore aliases
                    if ($network['aliases'] ?? false) {
                        continue;
                    }
                    $networkExists = $networks->contains(function ($value, $key) use ($networkName) {
                        return $value == $networkName || $key == $networkName;
                    });
                    if (! $networkExists) {
                        $networks->put($networkName, null);
                    }
                }
            }
            $baseNetworkExists = $networks->contains(function ($value, $_) use ($baseNetwork) {
                return $value == $baseNetwork;
            });
            if (! $baseNetworkExists) {
                foreach ($baseNetwork as $network) {
                    $topLevel->get('networks')->put($network, [
                        'name' => $network,
                        'external' => true,
                    ]);
                }
            }
            $networks_temp = collect();

            foreach ($networks as $key => $network) {
                if (gettype($network) === 'string') {
                    // networks:
                    //  - appwrite
                    $networks_temp->put($network, null);
                } elseif (gettype($network) === 'array') {
                    // networks:
                    //   default:
                    //     ipv4_address: 192.168.203.254
                    $networks_temp->put($key, $network);
                }
            }
            foreach ($baseNetwork as $key => $network) {
                $networks_temp->put($network, null);
            }

            // Convert
            // - SESSION_SECRET: 123 to - SESSION_SECRET=123
            $convertedServiceVariables = collect([]);
            foreach ($environment as $variableName => $variable) {
                if (is_numeric($variableName)) {
                    if (is_array($variable)) {
                        $key = str(collect($variable)->keys()->first());
                        $value = str(collect($variable)->values()->first());
                        $variable = "$key=$value";
                        $convertedServiceVariables->put($variableName, $variable);
                    } elseif (is_string($variable)) {
                        $convertedServiceVariables->put($variableName, $variable);
                    }
                } elseif (is_string($variableName)) {
                    $convertedServiceVariables->put($variableName, $variable);
                }
            }
            $environment = $convertedServiceVariables;

            // filter magic environments
            $magicEnvironments = $environment->filter(function ($value, $key) {
                return str($key)->startsWith('SERVICE_FQDN') || str($key)->startsWith('SERVICE_URL') || str($value)->startsWith('SERVICE_FQDN') || str($value)->startsWith('SERVICE_URL');
            });
            if ($magicEnvironments->count() > 0) {
                foreach ($magicEnvironments as $key => $value) {
                    $key = str($key);
                    $value = str($value);
                    $command = $key->after('SERVICE_')->beforeLast('_');
                    if ($command->value() === 'FQDN') {
                        $fqdn = generateFqdn($server, "{$savedService->name}-{$uuid}");
                        if ($value && get_class($value) === 'Illuminate\Support\Stringable' && $value->startsWith('/')) {
                            $path = $value->value();
                            $value = "$fqdn$path";
                        } else {
                            $value = $fqdn;
                        }
                    } elseif ($command->value() === 'URL') {
                        $fqdn = generateFqdn($server, "{$savedService->name}-{$uuid}");
                        $value = str($fqdn)->replace('http://', '')->replace('https://', '')->replace('www.', '');
                    }
                    if (! $isDatabase && ! $this->environment_variables()->where('key', $key)->where('service_id', $this->id)->first()) {
                        $savedService->fqdn = $value;
                        $savedService->save();
                    }
                    $this->environment_variables()->where('key', $key)->where('service_id', $this->id)->firstOrCreate([
                        'key' => $key,
                        'service_id' => $this->id,
                    ], [
                        'value' => $value,
                        'is_build_time' => false,
                        'is_preview' => false,
                    ]);
                }
            }
            foreach ($environment as $key => $value) {
                if (is_numeric($key)) {
                    if (is_array($value)) {
                        // - SESSION_SECRET: 123
                        // - SESSION_SECRET:
                        $key = str(collect($value)->keys()->first());
                        $value = str(collect($value)->values()->first());
                    } else {
                        $variable = str($value);
                        if ($variable->contains('=')) {
                            // - SESSION_SECRET=123
                            // - SESSION_SECRET=
                            $key = $variable->before('=');
                            $value = $variable->after('=');
                        } else {
                            // - SESSION_SECRET
                            $key = $variable;
                            $value = null;
                        }
                    }
                } else {
                    // SESSION_SECRET: 123
                    // SESSION_SECRET:
                    $key = str($key);
                    $value = str($value);
                }

                // Auto generate FQDN and URL
                // environment:
                //   - SERVICE_FQDN_UMAMI=/umami
                //   - FQDN=$SERVICE_FQDN_UMAMI
                //   - URL=$SERVICE_URL_UMAMI
                //   - TEST=${TEST:-initial}
                //   - HARDCODED=stuff

                if ($value->startsWith('$')) {
                    $value = str(replaceVariables($value));
                    if ($value->startsWith('SERVICE_')) {
                        // $value = SERVICE_FQDN_UMAMI
                        $command = $value->after('SERVICE_')->beforeLast('_');
                        if ($command->value() === 'FQDN') {
                            if ($magicEnvironments->has($value->value())) {
                                $found = $magicEnvironments->get($value->value());
                                if ($found) {
                                    $found = $this->environment_variables()->where('key', $value->value())->where('service_id', $this->id)->first();
                                    if ($found) {
                                        $value = $found->value;
                                    }
                                }
                            } else {
                                $fqdn = generateFqdn($server, "{$savedService->name}-{$uuid}");
                                if ($value && get_class($value) === 'Illuminate\Support\Stringable' && $value->startsWith('/')) {
                                    $path = $value->value();
                                    $value = "$fqdn$path";
                                } else {
                                    $value = $fqdn;
                                }
                            }
                        } elseif ($command->value() === 'URL') {
                            if ($magicEnvironments->has($value->value())) {
                                $found = $magicEnvironments->get($value->value());
                                if ($found) {
                                    $found = $this->environment_variables()->where('key', $value->value())->where('service_id', $this->id)->first();
                                    if ($found) {
                                        $value = str($found->value)->replace('http://', '')->replace('https://', '')->replace('www.', '');
                                    }
                                }
                            } else {
                                $fqdn = generateFqdn($server, "{$savedService->name}-{$uuid}");
                                $value = str($fqdn)->replace('http://', '')->replace('https://', '')->replace('www.', '');
                            }
                        } else {
                            $value = generateEnvValue($command, $this);
                        }
                        $this->environment_variables()->where('key', $key)->where('service_id', $this->id)->firstOrCreate([
                            'key' => $key,
                            'service_id' => $this->id,
                        ], [
                            'value' => $value,
                            'is_build_time' => false,
                            'is_preview' => false,
                        ]);
                    } else {
                        if ($value->contains(':-')) {
                            $key = $value->before(':');
                            $value = $value->after(':-');
                        } elseif ($value->contains('-')) {
                            $key = $value->before('-');
                            $value = $value->after('-');
                        } elseif ($value->contains(':?')) {
                            $key = $value->before(':');
                            $value = $value->after(':?');
                        } elseif ($value->contains('?')) {
                            $key = $value->before('?');
                            $value = $value->after('?');
                        } else {
                            $key = $value;
                            $value = null;
                        }
                        $this->environment_variables()->where('key', $key)->where('service_id', $this->id)->firstOrCreate([
                            'key' => $key,
                            'service_id' => $this->id,
                        ], [
                            'value' => $value,
                            'is_build_time' => false,
                            'is_preview' => false,
                        ]);
                    }
                }

                if ($this->environment_variables->where('key', 'COOLIFY_CONTAINER_NAME')->isEmpty()) {
                    $environment->put('COOLIFY_CONTAINER_NAME', $containerName);
                }
                // Remove SERVICE_FQDN and SERVICE_URL from environment
                $environment = $environment->filter(function ($value, $key) {
                    return ! str($key)->startsWith('SERVICE_FQDN') && ! str($key)->startsWith('SERVICE_URL');
                });

            }
            if ($savedService->serviceType()) {
                $fqdns = generateServiceSpecificFqdns($savedService);
            } else {
                $fqdns = collect(data_get($savedService, 'fqdns'))->filter();
            }
            $defaultLabels = defaultLabels($this->id, $containerName, type: 'service', subType: $isDatabase ? 'database' : 'application', subId: $savedService->id);
            $serviceLabels = $labels->merge($defaultLabels);
            if (! $isDatabase && $fqdns->count() > 0) {
                if ($fqdns) {
                    $shouldGenerateLabelsExactly = $this->server->settings->generate_exact_labels;
                    if ($shouldGenerateLabelsExactly) {
                        switch ($this->server->proxyType()) {
                            case ProxyTypes::TRAEFIK->value:
                                $serviceLabels = $serviceLabels->merge(fqdnLabelsForTraefik(
                                    uuid: $this->uuid,
                                    domains: $fqdns,
                                    is_force_https_enabled: true,
                                    serviceLabels: $serviceLabels,
                                    is_gzip_enabled: $savedService->isGzipEnabled(),
                                    is_stripprefix_enabled: $savedService->isStripprefixEnabled(),
                                    service_name: $serviceName,
                                    image: data_get($service, 'image')
                                ));
                                break;
                            case ProxyTypes::CADDY->value:
                                $serviceLabels = $serviceLabels->merge(fqdnLabelsForCaddy(
                                    network: $this->destination->network,
                                    uuid: $this->uuid,
                                    domains: $fqdns,
                                    is_force_https_enabled: true,
                                    serviceLabels: $serviceLabels,
                                    is_gzip_enabled: $savedService->isGzipEnabled(),
                                    is_stripprefix_enabled: $savedService->isStripprefixEnabled(),
                                    service_name: $serviceName,
                                    image: data_get($service, 'image')
                                ));
                                break;
                        }
                    } else {
                        $serviceLabels = $serviceLabels->merge(fqdnLabelsForTraefik(
                            uuid: $this->uuid,
                            domains: $fqdns,
                            is_force_https_enabled: true,
                            serviceLabels: $serviceLabels,
                            is_gzip_enabled: $savedService->isGzipEnabled(),
                            is_stripprefix_enabled: $savedService->isStripprefixEnabled(),
                            service_name: $serviceName,
                            image: data_get($service, 'image')
                        ));
                        $serviceLabels = $serviceLabels->merge(fqdnLabelsForCaddy(
                            network: $this->destination->network,
                            uuid: $this->uuid,
                            domains: $fqdns,
                            is_force_https_enabled: true,
                            serviceLabels: $serviceLabels,
                            is_gzip_enabled: $savedService->isGzipEnabled(),
                            is_stripprefix_enabled: $savedService->isStripprefixEnabled(),
                            service_name: $serviceName,
                            image: data_get($service, 'image')
                        ));
                    }
                }
            }
            $payload = collect($service)->merge([
                'restart' => $restart->value(),
                'container_name' => $containerName,
                'volumes' => $volumesParsed,
                'networks' => $networks_temp,
                'labels' => $serviceLabels,
                'environment' => $environment,
            ]);

            if ($logging) {
                $payload['logging'] = $logging;
            }

            $parsedServices->put($serviceName, $payload);
        }

        $topLevel->put('services', $parsedServices);
        $customOrder = ['services', 'volumes', 'networks', 'configs', 'secrets'];

        $topLevel = $topLevel->sortBy(function ($value, $key) use ($customOrder) {
            return array_search($key, $customOrder);
        });
        $this->docker_compose = Yaml::dump(convertToArray($topLevel), 10, 2);
        data_forget($this, 'environment_variables');
        data_forget($this, 'environment_variables_preview');
        $this->save();

        return $topLevel;

    }

    public function parse(bool $isNew = false): Collection
    {
        if ($this->compose_parsing_version === '3') {
            return $this->newParser();
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
