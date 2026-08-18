<?php

use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\CloudInitScript;
use App\Models\CloudProviderToken;
use App\Models\DiscordNotificationSettings;
use App\Models\EmailNotificationSettings;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\LocalFileVolume;
use App\Models\OauthSetting;
use App\Models\PrivateKey;
use App\Models\PushoverNotificationSettings;
use App\Models\S3Storage;
use App\Models\Server;
use App\Models\ServerSetting;
use App\Models\Service;
use App\Models\SharedEnvironmentVariable;
use App\Models\SlackNotificationSettings;
use App\Models\SslCertificate;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\TelegramNotificationSettings;
use App\Models\WebhookNotificationSettings;

describe('Sensitive model fields are hidden by default', function () {
    test('ServerSetting hides sentinel and logdrain secrets', function () {
        $hidden = (new ServerSetting)->getHidden();

        expect($hidden)->toContain(
            'sentinel_token',
            'sentinel_custom_url',
            'logdrain_newrelic_license_key',
            'logdrain_axiom_api_key',
            'logdrain_custom_config',
            'logdrain_custom_config_parser',
        );
    });

    test('Server hides logdrain api keys', function () {
        $hidden = (new Server)->getHidden();

        expect($hidden)->toContain(
            'logdrain_axiom_api_key',
            'logdrain_newrelic_license_key',
        );
    });

    test('Application hides webhook secrets, dockerfile, compose, and labels', function () {
        $hidden = (new Application)->getHidden();

        expect($hidden)->toContain(
            'http_basic_auth_password',
            'manual_webhook_secret_github',
            'manual_webhook_secret_gitlab',
            'manual_webhook_secret_bitbucket',
            'manual_webhook_secret_gitea',
            'dockerfile',
            'docker_compose',
            'docker_compose_raw',
            'custom_labels',
        );
    });

    test('EnvironmentVariable hides value and real_value', function () {
        $hidden = (new EnvironmentVariable)->getHidden();

        expect($hidden)->toContain('value', 'real_value');
    });

    test('SharedEnvironmentVariable hides value', function () {
        $hidden = (new SharedEnvironmentVariable)->getHidden();

        expect($hidden)->toContain('value');
    });

    test('Service hides docker_compose and docker_compose_raw', function () {
        $hidden = (new Service)->getHidden();

        expect($hidden)->toContain('docker_compose', 'docker_compose_raw');
    });

    test('ApplicationDeploymentQueue hides logs', function () {
        $hidden = (new ApplicationDeploymentQueue)->getHidden();

        expect($hidden)->toContain('logs');
    });

    test('LocalFileVolume hides file content', function () {
        $hidden = (new LocalFileVolume)->getHidden();

        expect($hidden)->toContain('content');
    });

    test('PrivateKey hides private key material', function () {
        $hidden = (new PrivateKey)->getHidden();

        expect($hidden)->toContain('private_key');
    });

    test('CloudProviderToken hides provider token', function () {
        $hidden = (new CloudProviderToken)->getHidden();

        expect($hidden)->toContain('token');
    });

    test('CloudInitScript hides script content', function () {
        $hidden = (new CloudInitScript)->getHidden();

        expect($hidden)->toContain('script');
    });

    test('S3Storage hides credentials', function () {
        $hidden = (new S3Storage)->getHidden();

        expect($hidden)->toContain('key', 'secret');
    });

    test('OauthSetting hides client secret', function () {
        $hidden = (new OauthSetting)->getHidden();

        expect($hidden)->toContain('client_secret');
    });

    test('SslCertificate hides private key', function () {
        $hidden = (new SslCertificate)->getHidden();

        expect($hidden)->toContain('ssl_private_key');
    });

    test('EmailNotificationSettings hides SMTP and resend secrets', function () {
        $hidden = (new EmailNotificationSettings)->getHidden();

        expect($hidden)->toContain(
            'smtp_from_address',
            'smtp_from_name',
            'smtp_recipients',
            'smtp_host',
            'smtp_username',
            'smtp_password',
            'resend_api_key',
        );
    });

    test('InstanceSettings hides instance notification secrets', function () {
        $hidden = (new InstanceSettings)->getHidden();

        expect($hidden)->toContain(
            'smtp_from_address',
            'smtp_from_name',
            'smtp_recipients',
            'smtp_host',
            'smtp_username',
            'smtp_password',
            'resend_api_key',
            'sentinel_token',
        );
    });

    test('Webhook-style notification settings hide delivery endpoints', function () {
        expect((new DiscordNotificationSettings)->getHidden())->toContain('discord_webhook_url');
        expect((new SlackNotificationSettings)->getHidden())->toContain('slack_webhook_url');
        expect((new WebhookNotificationSettings)->getHidden())->toContain('webhook_url');
    });

    test('PushoverNotificationSettings hides credentials', function () {
        $hidden = (new PushoverNotificationSettings)->getHidden();

        expect($hidden)->toContain('pushover_user_key', 'pushover_api_token');
    });

    test('TelegramNotificationSettings hides bot, chat, and thread identifiers', function () {
        $hidden = (new TelegramNotificationSettings)->getHidden();

        expect($hidden)->toContain(
            'telegram_token',
            'telegram_chat_id',
            'telegram_notifications_deployment_success_thread_id',
            'telegram_notifications_deployment_failure_thread_id',
            'telegram_notifications_status_change_thread_id',
            'telegram_notifications_backup_success_thread_id',
            'telegram_notifications_backup_failure_thread_id',
            'telegram_notifications_scheduled_task_success_thread_id',
            'telegram_notifications_scheduled_task_failure_thread_id',
            'telegram_notifications_docker_cleanup_success_thread_id',
            'telegram_notifications_docker_cleanup_failure_thread_id',
            'telegram_notifications_server_disk_usage_thread_id',
            'telegram_notifications_server_reachable_thread_id',
            'telegram_notifications_server_unreachable_thread_id',
            'telegram_notifications_server_patch_thread_id',
            'telegram_notifications_traefik_outdated_thread_id',
        );
    });

    test('TelegramNotificationSettings casts actual docker cleanup thread ids as encrypted', function () {
        $casts = (new TelegramNotificationSettings)->getCasts();

        expect($casts)->toMatchArray([
            'telegram_notifications_docker_cleanup_success_thread_id' => 'encrypted',
            'telegram_notifications_docker_cleanup_failure_thread_id' => 'encrypted',
        ]);
    });

    test('StandalonePostgresql hides password, init_scripts, db urls', function () {
        $hidden = (new StandalonePostgresql)->getHidden();

        expect($hidden)->toContain(
            'postgres_password',
            'init_scripts',
            'internal_db_url',
            'external_db_url',
        );
    });

    test('StandaloneMysql hides passwords and db urls', function () {
        $hidden = (new StandaloneMysql)->getHidden();

        expect($hidden)->toContain(
            'mysql_password',
            'mysql_root_password',
            'internal_db_url',
            'external_db_url',
        );
    });

    test('StandaloneMariadb hides passwords and db urls', function () {
        $hidden = (new StandaloneMariadb)->getHidden();

        expect($hidden)->toContain(
            'mariadb_password',
            'mariadb_root_password',
            'internal_db_url',
            'external_db_url',
        );
    });

    test('StandaloneMongodb hides root password and db urls', function () {
        $hidden = (new StandaloneMongodb)->getHidden();

        expect($hidden)->toContain(
            'mongo_initdb_root_password',
            'internal_db_url',
            'external_db_url',
        );
    });

    test('StandaloneRedis hides password and db urls', function () {
        $hidden = (new StandaloneRedis)->getHidden();

        expect($hidden)->toContain(
            'redis_password',
            'internal_db_url',
            'external_db_url',
        );
    });

    test('StandaloneClickhouse hides password and db urls', function () {
        $hidden = (new StandaloneClickhouse)->getHidden();

        expect($hidden)->toContain(
            'clickhouse_admin_password',
            'internal_db_url',
            'external_db_url',
        );
    });

    test('StandaloneKeydb hides password and db urls', function () {
        $hidden = (new StandaloneKeydb)->getHidden();

        expect($hidden)->toContain(
            'keydb_password',
            'internal_db_url',
            'external_db_url',
        );
    });

    test('StandaloneDragonfly hides password and db urls', function () {
        $hidden = (new StandaloneDragonfly)->getHidden();

        expect($hidden)->toContain(
            'dragonfly_password',
            'internal_db_url',
            'external_db_url',
        );
    });
});

describe('Sensitive fields are absent from toArray() by default', function () {
    test('ServerSetting::toArray() excludes sentinel_custom_url by default', function () {
        $setting = new ServerSetting;
        $setting->setRawAttributes([
            'server_id' => 1,
            'sentinel_custom_url' => 'https://secret.example.com',
            'wildcard_domain' => 'public.example.com',
        ], sync: true);

        $array = $setting->toArray();

        expect($array)->not->toHaveKey('sentinel_custom_url');
        expect($array)->toHaveKey('wildcard_domain');
    });

    test('ServerSetting::toArray() includes sentinel_custom_url after makeVisible', function () {
        $setting = new ServerSetting;
        $setting->setRawAttributes([
            'server_id' => 1,
            'sentinel_custom_url' => 'https://secret.example.com',
        ], sync: true);

        $setting->makeVisible(['sentinel_custom_url']);
        $array = $setting->toArray();

        expect($array['sentinel_custom_url'])->toBe('https://secret.example.com');
    });
});
