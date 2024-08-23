<?php

namespace App\Models;

use App\Enums\ProxyTypes;
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
        'destination_type' => ['type' => 'integer', 'description' => 'The unique identifier of the destination where the service is running.'],
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

    public function parse(bool $isNew = false): Collection
    {
        if (! $this->docker_compose_raw) {
            return collect([]);
        }

        try {
            $yaml = Yaml::parse($this->docker_compose_raw);
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
        $allServices = get_service_templates();
        $topLevelVolumes = collect(data_get($yaml, 'volumes', []));
        $topLevelNetworks = collect(data_get($yaml, 'networks', []));
        $topLevelConfigs = collect(data_get($yaml, 'configs', []));
        $topLevelSecrets = collect(data_get($yaml, 'secrets', []));
        $services = data_get($yaml, 'services');

        $generatedServiceFQDNS = collect([]);
        if (is_null($this->destination)) {
            $destination = $this->server->destinations()->first();
            if ($destination) {
                $this->destination()->associate($destination);
                $this->save();
            }
        }
        $definedNetwork = collect([$this->uuid]);
        if ($topLevelVolumes->count() > 0) {
            $tempTopLevelVolumes = collect([]);
            foreach ($topLevelVolumes as $volumeName => $volume) {
                if (is_null($volume)) {
                    continue;
                }
                $tempTopLevelVolumes->put($volumeName, $volume);
            }
            $topLevelVolumes = collect($tempTopLevelVolumes);
        }
        $services = collect($services)->map(function ($service, $serviceName) use ($topLevelNetworks, $definedNetwork, $isNew, $generatedServiceFQDNS, $allServices, $topLevelVolumes) {
            // Workarounds for beta users.
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
            // End of workarounds for beta users.
            $serviceVolumes = collect(data_get($service, 'volumes', []));
            $servicePorts = collect(data_get($service, 'ports', []));
            $serviceNetworks = collect(data_get($service, 'networks', []));
            $serviceVariables = collect(data_get($service, 'environment', []));
            $serviceLabels = collect(data_get($service, 'labels', []));
            $hasHostNetworkMode = data_get($service, 'network_mode') === 'host' ? true : false;
            if ($serviceLabels->count() > 0) {
                $removedLabels = collect([]);
                $serviceLabels = $serviceLabels->filter(function ($serviceLabel, $serviceLabelName) use ($removedLabels) {
                    if (! str($serviceLabel)->contains('=')) {
                        $removedLabels->put($serviceLabelName, $serviceLabel);

                        return false;
                    }

                    return $serviceLabel;
                });
                foreach ($removedLabels as $removedLabelName => $removedLabel) {
                    $serviceLabels->push("$removedLabelName=$removedLabel");
                }
            }

            $containerName = "$serviceName-{$this->uuid}";

            // Decide if the service is a database
            $isDatabase = isDatabaseImage(data_get_str($service, 'image'));
            $image = data_get_str($service, 'image');
            data_set($service, 'is_database', $isDatabase);

            // Create new serviceApplication or serviceDatabase
            if ($isDatabase) {
                if ($isNew) {
                    $savedService = ServiceDatabase::create([
                        'name' => $serviceName,
                        'image' => $image,
                        'service_id' => $this->id,
                    ]);
                } else {
                    $savedService = ServiceDatabase::where([
                        'name' => $serviceName,
                        'service_id' => $this->id,
                    ])->first();
                }
            } else {
                if ($isNew) {
                    $savedService = ServiceApplication::create([
                        'name' => $serviceName,
                        'image' => $image,
                        'service_id' => $this->id,
                    ]);
                } else {
                    $savedService = ServiceApplication::where([
                        'name' => $serviceName,
                        'service_id' => $this->id,
                    ])->first();
                }
            }
            if (is_null($savedService)) {
                if ($isDatabase) {
                    $savedService = ServiceDatabase::create([
                        'name' => $serviceName,
                        'image' => $image,
                        'service_id' => $this->id,
                    ]);
                } else {
                    $savedService = ServiceApplication::create([
                        'name' => $serviceName,
                        'image' => $image,
                        'service_id' => $this->id,
                    ]);
                }
            }

            // Check if image changed
            if ($savedService->image !== $image) {
                $savedService->image = $image;
                $savedService->save();
            }
            // Collect/create/update networks
            if ($serviceNetworks->count() > 0) {
                foreach ($serviceNetworks as $networkName => $networkDetails) {
                    if ($networkName === 'default') {
                        continue;
                    }
                    // ignore alias
                    if ($networkDetails['aliases'] ?? false) {
                        continue;
                    }
                    $networkExists = $topLevelNetworks->contains(function ($value, $key) use ($networkName) {
                        return $value == $networkName || $key == $networkName;
                    });
                    if (! $networkExists) {
                        $topLevelNetworks->put($networkDetails, null);
                    }
                }
            }

            // Collect/create/update ports
            $collectedPorts = collect([]);
            if ($servicePorts->count() > 0) {
                foreach ($servicePorts as $sport) {
                    if (is_string($sport) || is_numeric($sport)) {
                        $collectedPorts->push($sport);
                    }
                    if (is_array($sport)) {
                        $target = data_get($sport, 'target');
                        $published = data_get($sport, 'published');
                        $protocol = data_get($sport, 'protocol');
                        $collectedPorts->push("$target:$published/$protocol");
                    }
                }
            }
            $savedService->ports = $collectedPorts->implode(',');
            $savedService->save();

            if (! $hasHostNetworkMode) {
                // Add Coolify specific networks
                $definedNetworkExists = $topLevelNetworks->contains(function ($value, $_) use ($definedNetwork) {
                    return $value == $definedNetwork;
                });
                if (! $definedNetworkExists) {
                    foreach ($definedNetwork as $network) {
                        $topLevelNetworks->put($network, [
                            'name' => $network,
                            'external' => true,
                        ]);
                    }
                }
                $networks = collect();
                foreach ($serviceNetworks as $key => $serviceNetwork) {
                    if (gettype($serviceNetwork) === 'string') {
                        // networks:
                        //  - appwrite
                        $networks->put($serviceNetwork, null);
                    } elseif (gettype($serviceNetwork) === 'array') {
                        // networks:
                        //   default:
                        //     ipv4_address: 192.168.203.254
                        // $networks->put($serviceNetwork, null);
                        $networks->put($key, $serviceNetwork);
                    }
                }
                foreach ($definedNetwork as $key => $network) {
                    $networks->put($network, null);
                }
                data_set($service, 'networks', $networks->toArray());
            }

            // Collect/create/update volumes
            if ($serviceVolumes->count() > 0) {
                ['serviceVolumes' => $serviceVolumes, 'topLevelVolumes' => $topLevelVolumes] = parseServiceVolumes($serviceVolumes, $savedService, $topLevelVolumes);
                data_set($service, 'volumes', $serviceVolumes->toArray());
            }

            // Get variables from the service
            foreach ($serviceVariables as $variableName => $variable) {
                if (is_numeric($variableName)) {
                    if (is_array($variable)) {
                        // - SESSION_SECRET: 123
                        // - SESSION_SECRET:
                        $key = str(collect($variable)->keys()->first());
                        $value = str(collect($variable)->values()->first());
                    } else {
                        $variable = str($variable);
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
                    $key = str($variableName);
                    $value = str($variable);
                }
                if ($key->startsWith('SERVICE_FQDN')) {
                    if ($isNew || $savedService->fqdn === null) {
                        $name = $key->after('SERVICE_FQDN_')->beforeLast('_')->lower();
                        $fqdn = generateFqdn($this->server, "{$name->value()}-{$this->uuid}");
                        if (substr_count($key->value(), '_') === 3) {
                            // SERVICE_FQDN_UMAMI_1000
                            $port = $key->afterLast('_');
                        } else {
                            $last = $key->afterLast('_');
                            if (is_numeric($last->value())) {
                                // SERVICE_FQDN_3001
                                $port = $last;
                            } else {
                                // SERVICE_FQDN_UMAMI
                                $port = null;
                            }
                        }
                        if ($port) {
                            $fqdn = "$fqdn:$port";
                        }
                        if (substr_count($key->value(), '_') >= 2) {
                            if ($value) {
                                $path = $value->value();
                            } else {
                                $path = null;
                            }
                            if ($generatedServiceFQDNS->count() > 0) {
                                $alreadyGenerated = $generatedServiceFQDNS->has($key->value());
                                if ($alreadyGenerated) {
                                    $fqdn = $generatedServiceFQDNS->get($key->value());
                                } else {
                                    $generatedServiceFQDNS->put($key->value(), $fqdn);
                                }
                            } else {
                                $generatedServiceFQDNS->put($key->value(), $fqdn);
                            }
                            $fqdn = "$fqdn$path";
                        }

                        if (! $isDatabase) {
                            if ($savedService->fqdn) {
                                data_set($savedService, 'fqdn', $savedService->fqdn.','.$fqdn);
                            } else {
                                data_set($savedService, 'fqdn', $fqdn);
                            }
                            $savedService->save();
                        }
                        EnvironmentVariable::create([
                            'key' => $key,
                            'value' => $fqdn,
                            'is_build_time' => false,
                            'service_id' => $this->id,
                            'is_preview' => false,
                        ]);
                    }
                    // Caddy needs exact port in some cases.
                    if ($predefinedPort && ! $key->endsWith("_{$predefinedPort}")) {
                        $fqdns_exploded = str($savedService->fqdn)->explode(',');
                        if ($fqdns_exploded->count() > 1) {
                            continue;
                        }
                        $env = EnvironmentVariable::where([
                            'key' => $key,
                            'service_id' => $this->id,
                        ])->first();
                        if ($env) {
                            $env_url = Url::fromString($savedService->fqdn);
                            $env_port = $env_url->getPort();
                            if ($env_port !== $predefinedPort) {
                                $env_url = $env_url->withPort($predefinedPort);
                                $savedService->fqdn = $env_url->__toString();
                                $savedService->save();
                            }
                        }
                    }

                    // data_forget($service, "environment.$variableName");
                    // $yaml = data_forget($yaml, "services.$serviceName.environment.$variableName");
                    // if (count(data_get($yaml, 'services.' . $serviceName . '.environment')) === 0) {
                    //     $yaml = data_forget($yaml, "services.$serviceName.environment");
                    // }
                    continue;
                }
                if ($value?->startsWith('$')) {
                    $foundEnv = EnvironmentVariable::where([
                        'key' => $key,
                        'service_id' => $this->id,
                    ])->first();
                    $value = str(replaceVariables($value));
                    $key = $value;
                    if ($value->startsWith('SERVICE_')) {
                        $foundEnv = EnvironmentVariable::where([
                            'key' => $key,
                            'service_id' => $this->id,
                        ])->first();
                        ['command' => $command, 'forService' => $forService, 'generatedValue' => $generatedValue, 'port' => $port] = parseEnvVariable($value);
                        if (! is_null($command)) {
                            if ($command?->value() === 'FQDN' || $command?->value() === 'URL') {
                                if (Str::lower($forService) === $serviceName) {
                                    $fqdn = generateFqdn($this->server, $containerName);
                                } else {
                                    $fqdn = generateFqdn($this->server, Str::lower($forService).'-'.$this->uuid);
                                }
                                if ($port) {
                                    $fqdn = "$fqdn:$port";
                                }
                                if ($foundEnv) {
                                    $fqdn = data_get($foundEnv, 'value');
                                    // if ($savedService->fqdn) {
                                    //     $savedServiceFqdn = Url::fromString($savedService->fqdn);
                                    //     $parsedFqdn = Url::fromString($fqdn);
                                    //     $savedServicePath = $savedServiceFqdn->getPath();
                                    //     $parsedFqdnPath = $parsedFqdn->getPath();
                                    //     if ($savedServicePath != $parsedFqdnPath) {
                                    //         $fqdn = $parsedFqdn->withPath($savedServicePath)->__toString();
                                    //         $foundEnv->value = $fqdn;
                                    //         $foundEnv->save();
                                    //     }
                                    // }
                                } else {
                                    if ($command->value() === 'URL') {
                                        $fqdn = str($fqdn)->after('://')->value();
                                    }
                                    EnvironmentVariable::create([
                                        'key' => $key,
                                        'value' => $fqdn,
                                        'is_build_time' => false,
                                        'service_id' => $this->id,
                                        'is_preview' => false,
                                    ]);
                                }
                                if (! $isDatabase) {
                                    if ($command->value() === 'FQDN' && is_null($savedService->fqdn) && ! $foundEnv) {
                                        $savedService->fqdn = $fqdn;
                                        $savedService->save();
                                    }
                                    // Caddy needs exact port in some cases.
                                    if ($predefinedPort && ! $key->endsWith("_{$predefinedPort}") && $command?->value() === 'FQDN' && $this->server->proxyType() === 'CADDY') {
                                        $fqdns_exploded = str($savedService->fqdn)->explode(',');
                                        if ($fqdns_exploded->count() > 1) {
                                            continue;
                                        }
                                        $env = EnvironmentVariable::where([
                                            'key' => $key,
                                            'service_id' => $this->id,
                                        ])->first();
                                        if ($env) {
                                            $env_url = Url::fromString($env->value);
                                            $env_port = $env_url->getPort();
                                            if ($env_port !== $predefinedPort) {
                                                $env_url = $env_url->withPort($predefinedPort);
                                                $savedService->fqdn = $env_url->__toString();
                                                $savedService->save();
                                            }
                                        }
                                    }
                                }
                            } else {
                                $generatedValue = generateEnvValue($command, $this);
                                if (! $foundEnv) {
                                    EnvironmentVariable::create([
                                        'key' => $key,
                                        'value' => $generatedValue,
                                        'is_build_time' => false,
                                        'service_id' => $this->id,
                                        'is_preview' => false,
                                    ]);
                                }
                            }
                        }
                    } else {
                        if ($value->contains(':-')) {
                            $key = $value->before(':');
                            $defaultValue = $value->after(':-');
                        } elseif ($value->contains('-')) {
                            $key = $value->before('-');
                            $defaultValue = $value->after('-');
                        } elseif ($value->contains(':?')) {
                            $key = $value->before(':');
                            $defaultValue = $value->after(':?');
                        } elseif ($value->contains('?')) {
                            $key = $value->before('?');
                            $defaultValue = $value->after('?');
                        } else {
                            $key = $value;
                            $defaultValue = null;
                        }
                        $foundEnv = EnvironmentVariable::where([
                            'key' => $key,
                            'service_id' => $this->id,
                        ])->first();
                        if ($foundEnv) {
                            $defaultValue = data_get($foundEnv, 'value');
                        }
                        EnvironmentVariable::updateOrCreate([
                            'key' => $key,
                            'service_id' => $this->id,
                        ], [
                            'value' => $defaultValue,
                            'is_build_time' => false,
                            'service_id' => $this->id,
                            'is_preview' => false,
                        ]);
                    }
                }
            }
            // Add labels to the service
            if ($savedService->serviceType()) {
                $fqdns = generateServiceSpecificFqdns($savedService);
            } else {
                $fqdns = collect(data_get($savedService, 'fqdns'))->filter();
            }
            $defaultLabels = defaultLabels($this->id, $containerName, type: 'service', subType: $isDatabase ? 'database' : 'application', subId: $savedService->id);
            $serviceLabels = $serviceLabels->merge($defaultLabels);
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
            if ($this->server->isLogDrainEnabled() && $savedService->isLogDrainEnabled()) {
                data_set($service, 'logging', [
                    'driver' => 'fluentd',
                    'options' => [
                        'fluentd-address' => 'tcp://127.0.0.1:24224',
                        'fluentd-async' => 'true',
                        'fluentd-sub-second-precision' => 'true',
                    ],
                ]);
            }
            if ($serviceLabels->count() > 0) {
                if ($this->is_container_label_escape_enabled) {
                    $serviceLabels = $serviceLabels->map(function ($value, $key) {
                        return escapeDollarSign($value);
                    });
                }
            }
            data_set($service, 'labels', $serviceLabels->toArray());
            data_forget($service, 'is_database');
            if (! data_get($service, 'restart')) {
                data_set($service, 'restart', RESTART_MODE);
            }
            if (data_get($service, 'restart') === 'no' || data_get($service, 'exclude_from_hc')) {
                $savedService->update(['exclude_from_status' => true]);
            }
            data_set($service, 'container_name', $containerName);
            data_forget($service, 'volumes.*.content');
            data_forget($service, 'volumes.*.isDirectory');
            data_forget($service, 'volumes.*.is_directory');
            data_forget($service, 'exclude_from_hc');
            data_set($service, 'environment', $serviceVariables->toArray());
            updateCompose($savedService);

            return $service;

        });

        $envs_from_coolify = $this->environment_variables()->get();
        $services = collect($services)->map(function ($service, $serviceName) use ($envs_from_coolify) {
            $serviceVariables = collect(data_get($service, 'environment', []));
            $parsedServiceVariables = collect([]);
            foreach ($serviceVariables as $key => $value) {
                if (is_numeric($key)) {
                    $value = str($value);
                    if ($value->contains('=')) {
                        $key = $value->before('=')->value();
                        $value = $value->after('=')->value();
                    } else {
                        $key = $value->value();
                        $value = null;
                    }
                    $parsedServiceVariables->put($key, $value);
                } else {
                    $parsedServiceVariables->put($key, $value);
                }
            }
            $parsedServiceVariables->put('COOLIFY_CONTAINER_NAME', "$serviceName-{$this->uuid}");
            $parsedServiceVariables = $parsedServiceVariables->map(function ($value, $key) use ($envs_from_coolify) {
                if (! str($value)->startsWith('$')) {
                    $found_env = $envs_from_coolify->where('key', $key)->first();
                    if ($found_env) {
                        return $found_env->value;
                    }
                }

                return $value;
            });

            data_set($service, 'environment', $parsedServiceVariables->toArray());

            return $service;
        });
        $finalServices = [
            'services' => $services->toArray(),
            'volumes' => $topLevelVolumes->toArray(),
            'networks' => $topLevelNetworks->toArray(),
            'configs' => $topLevelConfigs->toArray(),
            'secrets' => $topLevelSecrets->toArray(),
        ];
        $yaml = data_forget($yaml, 'services.*.volumes.*.content');
        $this->docker_compose_raw = Yaml::dump($yaml, 10, 2);
        $this->docker_compose = Yaml::dump($finalServices, 10, 2);
        $this->save();
        $this->saveComposeConfigs();

        return collect($finalServices);

    }

    public function networks()
    {
        $networks = getTopLevelNetworks($this);

        return $networks;
    }
}
