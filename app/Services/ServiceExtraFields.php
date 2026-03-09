<?php

namespace App\Services;

use App\Models\Service;
use Illuminate\Support\Collection;

use function collect;
use function data_get;
use function filled;
use function str;

class ServiceExtraFields
{
    public function __construct(protected Service $service)
    {
    }

    public function get(): Collection
    {
        $fields = collect([]);
        $applications = $this->service->applications()->get();
        foreach ($applications as $application) {
            $image = str($application->image)->before(':');
            if ($image->isEmpty()) {
                continue;
            }
            switch ($image) {
                case $image->contains('tolgee'):
                    $data = collect([]);
                    $admin_password = $this->service->environment_variables()->where('key', 'SERVICE_PASSWORD_TOLGEE')->first();
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
                    $logto_endpoint = $this->service->environment_variables()->where('key', 'LOGTO_ENDPOINT')->first();
                    $logto_admin_endpoint = $this->service->environment_variables()->where('key', 'LOGTO_ADMIN_ENDPOINT')->first();
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
                    $admin_password = $this->service->environment_variables()->where('key', 'SERVICE_PASSWORD_UNLEASH')->first();
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
                    $admin_password = $this->service->environment_variables()->where('key', 'SERVICE_PASSWORD_GRAFANA')->first();
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
                case $image->contains('elasticsearch'):
                    $data = collect([]);
                    $elastic_password = $this->service->environment_variables()->where('key', 'SERVICE_PASSWORD_ELASTICSEARCH')->first();
                    if ($elastic_password) {
                        $data = $data->merge([
                            'Password (default user: elastic)' => [
                                'key' => data_get($elastic_password, 'key'),
                                'value' => data_get($elastic_password, 'value'),
                                'rules' => 'required',
                                'isPassword' => true,
                            ],
                        ]);
                    }
                    $fields->put('Elasticsearch', $data->toArray());
                    break;
                case $image->contains('directus'):
                    $data = collect([]);
                    $admin_email = $this->service->environment_variables()->where('key', 'ADMIN_EMAIL')->first();
                    $admin_password = $this->service->environment_variables()->where('key', 'SERVICE_PASSWORD_ADMIN')->first();

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
                    $dashboard_user = $this->service->environment_variables()->where('key', 'SERVICE_USER_ADMIN')->first();
                    $dashboard_password = $this->service->environment_variables()->where('key', 'SERVICE_PASSWORD_ADMIN')->first();
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
                    $console_url = $this->service->environment_variables()->where('key', 'MINIO_BROWSER_REDIRECT_URL')->first();
                    $s3_api_url = $this->service->environment_variables()->where('key', 'MINIO_SERVER_URL')->first();
                    $admin_user = $this->service->environment_variables()->where('key', 'SERVICE_USER_MINIO')->first();
                    if (is_null($admin_user)) {
                        $admin_user = $this->service->environment_variables()->where('key', 'MINIO_ROOT_USER')->first();
                    }
                    $admin_password = $this->service->environment_variables()->where('key', 'SERVICE_PASSWORD_MINIO')->first();
                    if (is_null($admin_password)) {
                        $admin_password = $this->service->environment_variables()->where('key', 'MINIO_ROOT_PASSWORD')->first();
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
                case $image->contains('garage'):
                    $data = collect([]);
                    $s3_api_url = $this->service->environment_variables()->where('key', 'GARAGE_S3_API_URL')->first();
                    $web_url = $this->service->environment_variables()->where('key', 'GARAGE_WEB_URL')->first();
                    $admin_url = $this->service->environment_variables()->where('key', 'GARAGE_ADMIN_URL')->first();
                    $admin_token = $this->service->environment_variables()->where('key', 'GARAGE_ADMIN_TOKEN')->first();
                    if (is_null($admin_token)) {
                        $admin_token = $this->service->environment_variables()->where('key', 'SERVICE_PASSWORD_GARAGE')->first();
                    }
                    $rpc_secret = $this->service->environment_variables()->where('key', 'GARAGE_RPC_SECRET')->first();
                    if (is_null($rpc_secret)) {
                        $rpc_secret = $this->service->environment_variables()->where('key', 'SERVICE_HEX_32_RPCSECRET')->first();
                    }
                    $metrics_token = $this->service->environment_variables()->where('key', 'GARAGE_METRICS_TOKEN')->first();
                    if (is_null($metrics_token)) {
                        $metrics_token = $this->service->environment_variables()->where('key', 'SERVICE_PASSWORD_GARAGEMETRICS')->first();
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
                    if ($web_url) {
                        $data = $data->merge([
                            'Web URL' => [
                                'key' => data_get($web_url, 'key'),
                                'value' => data_get($web_url, 'value'),
                                'rules' => 'required|url',
                            ],
                        ]);
                    }
                    if ($admin_url) {
                        $data = $data->merge([
                            'Admin URL' => [
                                'key' => data_get($admin_url, 'key'),
                                'value' => data_get($admin_url, 'value'),
                                'rules' => 'required|url',
                            ],
                        ]);
                    }
                    if ($admin_token) {
                        $data = $data->merge([
                            'Admin Token' => [
                                'key' => data_get($admin_token, 'key'),
                                'value' => data_get($admin_token, 'value'),
                                'rules' => 'required',
                                'isPassword' => true,
                            ],
                        ]);
                    }
                    if ($rpc_secret) {
                        $data = $data->merge([
                            'RPC Secret' => [
                                'key' => data_get($rpc_secret, 'key'),
                                'value' => data_get($rpc_secret, 'value'),
                                'rules' => 'required',
                                'isPassword' => true,
                            ],
                        ]);
                    }
                    if ($metrics_token) {
                        $data = $data->merge([
                            'Metrics Token' => [
                                'key' => data_get($metrics_token, 'key'),
                                'value' => data_get($metrics_token, 'value'),
                                'rules' => 'required',
                                'isPassword' => true,
                            ],
                        ]);
                    }

                    $fields->put('Garage', $data->toArray());
                    break;
                case $image->contains('weblate'):
                    $data = collect([]);
                    $admin_email = $this->service->environment_variables()->where('key', 'WEBLATE_ADMIN_EMAIL')->first();
                    $admin_password = $this->service->environment_variables()->where('key', 'SERVICE_PASSWORD_WEBLATE')->first();

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
                    $SERVICE_PASSWORD_MEILISEARCH = $this->service->environment_variables()->where('key', 'SERVICE_PASSWORD_MEILISEARCH')->first();
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
                case $image->contains('linkding'):
                    $data = collect([]);
                    $SERVICE_USER_LINKDING = $this->service->environment_variables()->where('key', 'SERVICE_USER_LINKDING')->first();
                    $SERVICE_PASSWORD_LINKDING = $this->service->environment_variables()->where('key', 'SERVICE_PASSWORD_LINKDING')->first();
                    if ($SERVICE_USER_LINKDING) {
                        $data = $data->merge([
                            'Superuser Name' => [
                                'key' => data_get($SERVICE_USER_LINKDING, 'key'),
                                'value' => data_get($SERVICE_USER_LINKDING, 'value'),
                            ],
                        ]);
                    }
                    if ($SERVICE_PASSWORD_LINKDING) {
                        $data = $data->merge([
                            'Superuser Password' => [
                                'key' => data_get($SERVICE_PASSWORD_LINKDING, 'key'),
                                'value' => data_get($SERVICE_PASSWORD_LINKDING, 'value'),
                                'isPassword' => true,
                            ],
                        ]);
                    }

                    $fields->put('Linkding', $data->toArray());
                    break;
                case $image->contains('ghost'):
                    $data = collect([]);
                    $MAIL_OPTIONS_AUTH_PASS = $this->service->environment_variables()->where('key', 'MAIL_OPTIONS_AUTH_PASS')->first();
                    $MAIL_OPTIONS_AUTH_USER = $this->service->environment_variables()->where('key', 'MAIL_OPTIONS_AUTH_USER')->first();
                    $MAIL_OPTIONS_SECURE = $this->service->environment_variables()->where('key', 'MAIL_OPTIONS_SECURE')->first();
                    $MAIL_OPTIONS_PORT = $this->service->environment_variables()->where('key', 'MAIL_OPTIONS_PORT')->first();
                    $MAIL_OPTIONS_SERVICE = $this->service->environment_variables()->where('key', 'MAIL_OPTIONS_SERVICE')->first();
                    $MAIL_OPTIONS_HOST = $this->service->environment_variables()->where('key', 'MAIL_OPTIONS_HOST')->first();
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

                    $DATABASE_URL = $this->service->environment_variables()->where('key', 'DATABASE_URL')->first();
                    $ADMIN_TOKEN = $this->service->environment_variables()->where('key', 'SERVICE_PASSWORD_64_ADMIN')->first();
                    $SIGNUP_ALLOWED = $this->service->environment_variables()->where('key', 'SIGNUP_ALLOWED')->first();
                    $PUSH_ENABLED = $this->service->environment_variables()->where('key', 'PUSH_ENABLED')->first();
                    $PUSH_INSTALLATION_ID = $this->service->environment_variables()->where('key', 'PUSH_SERVICE_ID')->first();
                    $PUSH_INSTALLATION_KEY = $this->service->environment_variables()->where('key', 'PUSH_SERVICE_KEY')->first();

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
                    $password = $this->service->environment_variables()->where('key', 'SERVICE_PASSWORD_GITLAB')->first();
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
                    $password = $this->service->environment_variables()->where('key', 'SERVICE_PASSWORD_64_PASSWORDCODESERVER')->first();
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
                    $sudoPassword = $this->service->environment_variables()->where('key', 'SERVICE_PASSWORD_SUDOCODESERVER')->first();
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
                    $license = $this->service->environment_variables()->where('key', 'STRAPI_LICENSE')->first();
                    if ($license) {
                        $data = $data->merge([
                            'License' => [
                                'key' => data_get($license, 'key'),
                                'value' => data_get($license, 'value'),
                            ],
                        ]);
                    }
                    $nodeEnv = $this->service->environment_variables()->where('key', 'NODE_ENV')->first();
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
                case $image->contains('marckohlbrugge/sessy'):
                    $data = collect([]);
                    $username = $this->service->environment_variables()->where('key', 'SERVICE_USER_SESSY')->first();
                    $password = $this->service->environment_variables()->where('key', 'SERVICE_PASSWORD_SESSY')->first();
                    if ($username) {
                        $data = $data->merge([
                            'HTTP Auth Username' => [
                                'key' => data_get($username, 'key'),
                                'value' => data_get($username, 'value'),
                                'rules' => 'required',
                            ],
                        ]);
                    }
                    if ($password) {
                        $data = $data->merge([
                            'HTTP Auth Password' => [
                                'key' => data_get($password, 'key'),
                                'value' => data_get($password, 'value'),
                                'rules' => 'required',
                                'isPassword' => true,
                            ],
                        ]);
                    }
                    $fields->put('Sessy', $data->toArray());
                    break;
                case $image->contains('coollabsio/openclaw'):
                    $data = collect([]);
                    $username = $this->service->environment_variables()->where('key', 'AUTH_USERNAME')->first();
                    $password = $this->service->environment_variables()->where('key', 'SERVICE_PASSWORD_OPENCLAW')->first();
                    $gateway_token = $this->service->environment_variables()->where('key', 'SERVICE_PASSWORD_64_GATEWAYTOKEN')->first();
                    if ($username) {
                        $data = $data->merge([
                            'Username' => [
                                'key' => data_get($username, 'key'),
                                'value' => data_get($username, 'value'),
                                'readonly' => true,
                            ],
                        ]);
                    }
                    if ($password) {
                        $data = $data->merge([
                            'Password' => [
                                'key' => data_get($password, 'key'),
                                'value' => data_get($password, 'value'),
                                'isPassword' => true,
                            ],
                        ]);
                    }
                    if ($gateway_token) {
                        $data = $data->merge([
                            'Gateway Token' => [
                                'key' => data_get($gateway_token, 'key'),
                                'value' => data_get($gateway_token, 'value'),
                                'isPassword' => true,
                            ],
                        ]);
                    }
                    $fields->put('Openclaw', $data->toArray());
                    break;
                default:
                    $data = collect([]);
                    $admin_user = $this->service->environment_variables()->where('key', 'SERVICE_USER_ADMIN')->first();
                    // Chaskiq
                    $admin_email = $this->service->environment_variables()->where('key', 'ADMIN_EMAIL')->first();

                    $admin_password = $this->service->environment_variables()->where('key', 'SERVICE_PASSWORD_ADMIN')->first();
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
        $databases = $this->service->databases()->get();

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
                    $postgres_user = $this->service->environment_variables()->whereIn('key', $userVariables)->first();
                    $postgres_password = $this->service->environment_variables()->whereIn('key', $passwordVariables)->first();
                    $postgres_db_name = $this->service->environment_variables()->whereIn('key', $dbNameVariables)->first();
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
                    $passwordVariables = ['SERVICE_PASSWORD_MYSQL', 'SERVICE_PASSWORD_WORDPRESS', 'MYSQL_PASSWORD', 'SERVICE_PASSWORD_64_MYSQL'];
                    $rootPasswordVariables = ['SERVICE_PASSWORD_MYSQLROOT', 'SERVICE_PASSWORD_ROOT', 'SERVICE_PASSWORD_64_MYSQLROOT'];
                    $dbNameVariables = ['MYSQL_DATABASE'];
                    $mysql_user = $this->service->environment_variables()->whereIn('key', $userVariables)->first();
                    $mysql_password = $this->service->environment_variables()->whereIn('key', $passwordVariables)->first();
                    $mysql_root_password = $this->service->environment_variables()->whereIn('key', $rootPasswordVariables)->first();
                    $mysql_db_name = $this->service->environment_variables()->whereIn('key', $dbNameVariables)->first();
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
                    $userVariables = ['SERVICE_USER_MARIADB', 'SERVICE_USER_WORDPRESS', 'SERVICE_USER_MYSQL', 'MYSQL_USER'];
                    $passwordVariables = ['SERVICE_PASSWORD_MARIADB', 'SERVICE_PASSWORD_WORDPRESS', '_APP_DB_PASS', 'MYSQL_PASSWORD'];
                    $rootPasswordVariables = ['SERVICE_PASSWORD_MARIADBROOT', 'SERVICE_PASSWORD_ROOT', '_APP_DB_ROOT_PASS', 'MYSQL_ROOT_PASSWORD'];
                    $dbNameVariables = ['SERVICE_DATABASE_MARIADB', 'SERVICE_DATABASE_WORDPRESS', '_APP_DB_SCHEMA', 'MYSQL_DATABASE'];

                    $mariadb_user = $this->service->environment_variables()->whereIn('key', $userVariables)->first();
                    $mariadb_password = $this->service->environment_variables()->whereIn('key', $passwordVariables)->first();
                    $mariadb_root_password = $this->service->environment_variables()->whereIn('key', $rootPasswordVariables)->first();
                    $mariadb_db_name = $this->service->environment_variables()->whereIn('key', $dbNameVariables)->first();
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
        $fields = collect($fields)->map(function ($extraFields) {
            if (is_array($extraFields)) {
                $extraFields = collect($extraFields)->map(function ($field) {
                    if (filled($field['value']) && str($field['value'])->startsWith('$SERVICE_')) {
                        $searchValue = str($field['value'])->after('$')->value;
                        $newValue = $this->service->environment_variables()->where('key', $searchValue)->first();
                        if ($newValue) {
                            $field['value'] = $newValue->value;
                        }
                    }

                    return $field;
                });
            }

            return $extraFields;
        });

        return $fields;
    }

    public function save(array $fields): void
    {
        foreach ($fields as $field) {
            $key = data_get($field, 'key');
            $value = data_get($field, 'value');
            $found = $this->service->environment_variables()->where('key', $key)->first();
            if ($found) {
                $found->value = $value;
                $found->save();
            } else {
                $this->service->environment_variables()->create([
                    'key' => $key,
                    'value' => $value,
                    'resourceable_id' => $this->service->id,
                    'resourceable_type' => $this->service->getMorphClass(),
                    'is_preview' => false,
                ]);
            }
        }
    }
}
