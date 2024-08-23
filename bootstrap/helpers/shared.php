<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Jobs\ServerFilesFromServerJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\InstanceSettings;
use App\Models\LocalFileVolume;
use App\Models\LocalPersistentVolume;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\StandaloneClickhouse;
use App\Models\StandaloneDragonfly;
use App\Models\StandaloneKeydb;
use App\Models\StandaloneMariadb;
use App\Models\StandaloneMongodb;
use App\Models\StandaloneMysql;
use App\Models\StandalonePostgresql;
use App\Models\StandaloneRedis;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Channels\DiscordChannel;
use App\Notifications\Channels\EmailChannel;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\Internal\GeneralNotification;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Mail\Message;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Process\Pool;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Support\Stringable;
use Lcobucci\JWT\Encoding\ChainedFormatter;
use Lcobucci\JWT\Encoding\JoseEncoder;
use Lcobucci\JWT\Signer\Hmac\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Builder;
use phpseclib3\Crypt\EC;
use phpseclib3\Crypt\RSA;
use Poliander\Cron\CronExpression;
use PurplePixie\PhpDns\DNSQuery;
use Spatie\Url\Url;
use Symfony\Component\Yaml\Yaml;
use Visus\Cuid2\Cuid2;

function base_configuration_dir(): string
{
    return '/data/coolify';
}
function application_configuration_dir(): string
{
    return base_configuration_dir().'/applications';
}
function service_configuration_dir(): string
{
    return base_configuration_dir().'/services';
}
function database_configuration_dir(): string
{
    return base_configuration_dir().'/databases';
}
function database_proxy_dir($uuid): string
{
    return base_configuration_dir()."/databases/$uuid/proxy";
}
function backup_dir(): string
{
    return base_configuration_dir().'/backups';
}
function metrics_dir(): string
{
    return base_configuration_dir().'/metrics';
}

function generate_readme_file(string $name, string $updated_at): string
{
    return "Resource name: $name\nLatest Deployment Date: $updated_at";
}

function isInstanceAdmin()
{
    return auth()?->user()?->isInstanceAdmin() ?? false;
}

function currentTeam()
{
    return auth()?->user()?->currentTeam() ?? null;
}

function showBoarding(): bool
{
    if (auth()->user()?->isMember()) {
        return false;
    }

    return currentTeam()->show_boarding ?? false;
}
function refreshSession(?Team $team = null): void
{
    if (! $team) {
        if (auth()->user()?->currentTeam()) {
            $team = Team::find(auth()->user()->currentTeam()->id);
        } else {
            $team = User::find(auth()->user()->id)->teams->first();
        }
    }
    Cache::forget('team:'.auth()->user()->id);
    Cache::remember('team:'.auth()->user()->id, 3600, function () use ($team) {
        return $team;
    });
    session(['currentTeam' => $team]);
}
function handleError(?Throwable $error = null, ?Livewire\Component $livewire = null, ?string $customErrorMessage = null)
{
    ray($error);
    if ($error instanceof TooManyRequestsException) {
        if (isset($livewire)) {
            return $livewire->dispatch('error', "Too many requests. Please try again in {$error->secondsUntilAvailable} seconds.");
        }

        return "Too many requests. Please try again in {$error->secondsUntilAvailable} seconds.";
    }
    if ($error instanceof UniqueConstraintViolationException) {
        if (isset($livewire)) {
            return $livewire->dispatch('error', 'Duplicate entry found. Please use a different name.');
        }

        return 'Duplicate entry found. Please use a different name.';
    }

    if ($error instanceof Throwable) {
        $message = $error->getMessage();
    } else {
        $message = null;
    }
    if ($customErrorMessage) {
        $message = $customErrorMessage.' '.$message;
    }

    if (isset($livewire)) {
        return $livewire->dispatch('error', $message);
    }
    throw new Exception($message);
}
function get_route_parameters(): array
{
    return Route::current()->parameters();
}

function get_latest_sentinel_version(): string
{
    try {
        $response = Http::get('https://cdn.coollabs.io/sentinel/versions.json');
        $versions = $response->json();

        return data_get($versions, 'sentinel.version');
    } catch (\Throwable $e) {
        //throw $e;
        ray($e->getMessage());

        return '0.0.0';
    }
}
function get_latest_version_of_coolify(): string
{
    try {
        $versions = File::get(base_path('versions.json'));
        $versions = json_decode($versions, true);

        return data_get($versions, 'coolify.v4.version');
    } catch (\Throwable $e) {
        ray($e->getMessage());

        return '0.0.0';
    }
}

function generate_random_name(?string $cuid = null): string
{
    $generator = new \Nubs\RandomNameGenerator\All(
        [
            new \Nubs\RandomNameGenerator\Alliteration,
        ]
    );
    if (is_null($cuid)) {
        $cuid = new Cuid2;
    }

    return Str::kebab("{$generator->getName()}-$cuid");
}
function generateSSHKey(string $type = 'rsa')
{
    if ($type === 'rsa') {
        $key = RSA::createKey();

        return [
            'private' => $key->toString('PKCS1'),
            'public' => $key->getPublicKey()->toString('OpenSSH', ['comment' => 'coolify-generated-ssh-key']),
        ];
    } elseif ($type === 'ed25519') {
        $key = EC::createKey('Ed25519');

        return [
            'private' => $key->toString('OpenSSH'),
            'public' => $key->getPublicKey()->toString('OpenSSH', ['comment' => 'coolify-generated-ssh-key']),
        ];
    }
    throw new Exception('Invalid key type');
}
function formatPrivateKey(string $privateKey)
{
    $privateKey = trim($privateKey);
    if (! str_ends_with($privateKey, "\n")) {
        $privateKey .= "\n";
    }

    return $privateKey;
}
function generate_application_name(string $git_repository, string $git_branch, ?string $cuid = null): string
{
    if (is_null($cuid)) {
        $cuid = new Cuid2;
    }

    return Str::kebab("$git_repository:$git_branch-$cuid");
}

function is_transactional_emails_active(): bool
{
    return isEmailEnabled(\App\Models\InstanceSettings::get());
}

function set_transanctional_email_settings(?InstanceSettings $settings = null): ?string
{
    if (! $settings) {
        $settings = \App\Models\InstanceSettings::get();
    }
    config()->set('mail.from.address', data_get($settings, 'smtp_from_address'));
    config()->set('mail.from.name', data_get($settings, 'smtp_from_name'));
    if (data_get($settings, 'resend_enabled')) {
        config()->set('mail.default', 'resend');
        config()->set('resend.api_key', data_get($settings, 'resend_api_key'));

        return 'resend';
    }
    if (data_get($settings, 'smtp_enabled')) {
        config()->set('mail.default', 'smtp');
        config()->set('mail.mailers.smtp', [
            'transport' => 'smtp',
            'host' => data_get($settings, 'smtp_host'),
            'port' => data_get($settings, 'smtp_port'),
            'encryption' => data_get($settings, 'smtp_encryption'),
            'username' => data_get($settings, 'smtp_username'),
            'password' => data_get($settings, 'smtp_password'),
            'timeout' => data_get($settings, 'smtp_timeout'),
            'local_domain' => null,
        ]);

        return 'smtp';
    }

    return null;
}

function base_ip(): string
{
    if (isDev()) {
        return 'localhost';
    }
    $settings = \App\Models\InstanceSettings::get();
    if ($settings->public_ipv4) {
        return "$settings->public_ipv4";
    }
    if ($settings->public_ipv6) {
        return "$settings->public_ipv6";
    }

    return 'localhost';
}
function getFqdnWithoutPort(string $fqdn)
{
    try {
        $url = Url::fromString($fqdn);
        $host = $url->getHost();
        $scheme = $url->getScheme();
        $path = $url->getPath();

        return "$scheme://$host$path";
    } catch (\Throwable $e) {
        return $fqdn;
    }
}
/**
 * If fqdn is set, return it, otherwise return public ip.
 */
function base_url(bool $withPort = true): string
{
    $settings = \App\Models\InstanceSettings::get();
    if ($settings->fqdn) {
        return $settings->fqdn;
    }
    $port = config('app.port');
    if ($settings->public_ipv4) {
        if ($withPort) {
            if (isDev()) {
                return "http://localhost:$port";
            }

            return "http://$settings->public_ipv4:$port";
        }
        if (isDev()) {
            return 'http://localhost';
        }

        return "http://$settings->public_ipv4";
    }
    if ($settings->public_ipv6) {
        if ($withPort) {
            return "http://$settings->public_ipv6:$port";
        }

        return "http://$settings->public_ipv6";
    }

    return url('/');
}

function isSubscribed()
{
    return isSubscriptionActive() || auth()->user()->isInstanceAdmin();
}
function isDev(): bool
{
    return config('app.env') === 'local';
}

function isCloud(): bool
{
    return ! config('coolify.self_hosted');
}

function validate_cron_expression($expression_to_validate): bool
{
    $isValid = false;
    $expression = new CronExpression($expression_to_validate);
    $isValid = $expression->isValid();

    if (isset(VALID_CRON_STRINGS[$expression_to_validate])) {
        $isValid = true;
    }

    return $isValid;
}
function send_internal_notification(string $message): void
{
    try {
        $team = Team::find(0);
        $team?->notify(new GeneralNotification($message));
    } catch (\Throwable $e) {
        ray($e->getMessage());
    }
}
function send_user_an_email(MailMessage $mail, string $email, ?string $cc = null): void
{
    $settings = \App\Models\InstanceSettings::get();
    $type = set_transanctional_email_settings($settings);
    if (! $type) {
        throw new Exception('No email settings found.');
    }
    if ($cc) {
        Mail::send(
            [],
            [],
            fn (Message $message) => $message
                ->to($email)
                ->replyTo($email)
                ->cc($cc)
                ->subject($mail->subject)
                ->html((string) $mail->render())
        );
    } else {
        Mail::send(
            [],
            [],
            fn (Message $message) => $message
                ->to($email)
                ->subject($mail->subject)
                ->html((string) $mail->render())
        );
    }
}
function isTestEmailEnabled($notifiable)
{
    if (data_get($notifiable, 'use_instance_email_settings') && isInstanceAdmin()) {
        return true;
    } elseif (data_get($notifiable, 'smtp_enabled') || data_get($notifiable, 'resend_enabled') && auth()->user()->isAdminFromSession()) {
        return true;
    }

    return false;
}
function isEmailEnabled($notifiable)
{
    return data_get($notifiable, 'smtp_enabled') || data_get($notifiable, 'resend_enabled') || data_get($notifiable, 'use_instance_email_settings');
}
function setNotificationChannels($notifiable, $event)
{
    $channels = [];
    $isEmailEnabled = isEmailEnabled($notifiable);
    $isDiscordEnabled = data_get($notifiable, 'discord_enabled');
    $isTelegramEnabled = data_get($notifiable, 'telegram_enabled');
    $isSubscribedToEmailEvent = data_get($notifiable, "smtp_notifications_$event");
    $isSubscribedToDiscordEvent = data_get($notifiable, "discord_notifications_$event");
    $isSubscribedToTelegramEvent = data_get($notifiable, "telegram_notifications_$event");

    if ($isDiscordEnabled && $isSubscribedToDiscordEvent) {
        $channels[] = DiscordChannel::class;
    }
    if ($isEmailEnabled && $isSubscribedToEmailEvent) {
        $channels[] = EmailChannel::class;
    }
    if ($isTelegramEnabled && $isSubscribedToTelegramEvent) {
        $channels[] = TelegramChannel::class;
    }

    return $channels;
}
function parseEnvFormatToArray($env_file_contents)
{
    $env_array = [];
    $lines = explode("\n", $env_file_contents);
    foreach ($lines as $line) {
        if ($line === '' || substr($line, 0, 1) === '#') {
            continue;
        }
        $equals_pos = strpos($line, '=');
        if ($equals_pos !== false) {
            $key = substr($line, 0, $equals_pos);
            $value = substr($line, $equals_pos + 1);
            if (substr($value, 0, 1) === '"' && substr($value, -1) === '"') {
                $value = substr($value, 1, -1);
            } elseif (substr($value, 0, 1) === "'" && substr($value, -1) === "'") {
                $value = substr($value, 1, -1);
            }
            $env_array[$key] = $value;
        }
    }

    return $env_array;
}

function data_get_str($data, $key, $default = null): Stringable
{
    $str = data_get($data, $key, $default) ?? $default;

    return str($str);
}

function generateFqdn(Server $server, string $random)
{
    $wildcard = data_get($server, 'settings.wildcard_domain');
    if (is_null($wildcard) || $wildcard === '') {
        $wildcard = sslip($server);
    }
    $url = Url::fromString($wildcard);
    $host = $url->getHost();
    $path = $url->getPath() === '/' ? '' : $url->getPath();
    $scheme = $url->getScheme();
    $finalFqdn = "$scheme://{$random}.$host$path";

    return $finalFqdn;
}
function sslip(Server $server)
{
    if (isDev() && $server->id === 0) {
        return 'http://127.0.0.1.sslip.io';
    }
    if ($server->ip === 'host.docker.internal') {
        $baseIp = base_ip();

        return "http://$baseIp.sslip.io";
    }

    return "http://{$server->ip}.sslip.io";
}

function get_service_templates(bool $force = false): Collection
{
    if ($force) {
        try {
            $response = Http::retry(3, 1000)->get(config('constants.services.official'));
            if ($response->failed()) {
                return collect([]);
            }
            $services = $response->json();

            return collect($services);
        } catch (\Throwable $e) {
            $services = File::get(base_path('templates/service-templates.json'));

            return collect(json_decode($services))->sortKeys();
        }
    } else {
        $services = File::get(base_path('templates/service-templates.json'));

        return collect(json_decode($services))->sortKeys();
    }
}

function getResourceByUuid(string $uuid, ?int $teamId = null)
{
    if (is_null($teamId)) {
        return null;
    }
    $resource = queryResourcesByUuid($uuid);
    if (! is_null($resource) && $resource->environment->project->team_id === $teamId) {
        return $resource;
    }

    return null;
}
function queryDatabaseByUuidWithinTeam(string $uuid, string $teamId)
{
    $postgresql = StandalonePostgresql::whereUuid($uuid)->first();
    if ($postgresql && $postgresql->team()->id == $teamId) {
        return $postgresql->unsetRelation('environment')->unsetRelation('destination');
    }
    $redis = StandaloneRedis::whereUuid($uuid)->first();
    if ($redis && $redis->team()->id == $teamId) {
        return $redis->unsetRelation('environment');
    }
    $mongodb = StandaloneMongodb::whereUuid($uuid)->first();
    if ($mongodb && $mongodb->team()->id == $teamId) {
        return $mongodb->unsetRelation('environment');
    }
    $mysql = StandaloneMysql::whereUuid($uuid)->first();
    if ($mysql && $mysql->team()->id == $teamId) {
        return $mysql->unsetRelation('environment');
    }
    $mariadb = StandaloneMariadb::whereUuid($uuid)->first();
    if ($mariadb && $mariadb->team()->id == $teamId) {
        return $mariadb->unsetRelation('environment');
    }
    $keydb = StandaloneKeydb::whereUuid($uuid)->first();
    if ($keydb && $keydb->team()->id == $teamId) {
        return $keydb->unsetRelation('environment');
    }
    $dragonfly = StandaloneDragonfly::whereUuid($uuid)->first();
    if ($dragonfly && $dragonfly->team()->id == $teamId) {
        return $dragonfly->unsetRelation('environment');
    }
    $clickhouse = StandaloneClickhouse::whereUuid($uuid)->first();
    if ($clickhouse && $clickhouse->team()->id == $teamId) {
        return $clickhouse->unsetRelation('environment');
    }

    return null;
}
function queryResourcesByUuid(string $uuid)
{
    $resource = null;
    $application = Application::whereUuid($uuid)->first();
    if ($application) {
        return $application;
    }
    $service = Service::whereUuid($uuid)->first();
    if ($service) {
        return $service;
    }
    $postgresql = StandalonePostgresql::whereUuid($uuid)->first();
    if ($postgresql) {
        return $postgresql;
    }
    $redis = StandaloneRedis::whereUuid($uuid)->first();
    if ($redis) {
        return $redis;
    }
    $mongodb = StandaloneMongodb::whereUuid($uuid)->first();
    if ($mongodb) {
        return $mongodb;
    }
    $mysql = StandaloneMysql::whereUuid($uuid)->first();
    if ($mysql) {
        return $mysql;
    }
    $mariadb = StandaloneMariadb::whereUuid($uuid)->first();
    if ($mariadb) {
        return $mariadb;
    }
    $keydb = StandaloneKeydb::whereUuid($uuid)->first();
    if ($keydb) {
        return $keydb;
    }
    $dragonfly = StandaloneDragonfly::whereUuid($uuid)->first();
    if ($dragonfly) {
        return $dragonfly;
    }
    $clickhouse = StandaloneClickhouse::whereUuid($uuid)->first();
    if ($clickhouse) {
        return $clickhouse;
    }

    return $resource;
}
function generatTagDeployWebhook($tag_name)
{
    $baseUrl = base_url();
    $api = Url::fromString($baseUrl).'/api/v1';
    $endpoint = "/deploy?tag=$tag_name";
    $url = $api.$endpoint;

    return $url;
}
function generateDeployWebhook($resource)
{
    $baseUrl = base_url();
    $api = Url::fromString($baseUrl).'/api/v1';
    $endpoint = '/deploy';
    $uuid = data_get($resource, 'uuid');
    $url = $api.$endpoint."?uuid=$uuid&force=false";

    return $url;
}
function generateGitManualWebhook($resource, $type)
{
    if ($resource->source_id !== 0 && ! is_null($resource->source_id)) {
        return null;
    }
    if ($resource->getMorphClass() === 'App\Models\Application') {
        $baseUrl = base_url();
        $api = Url::fromString($baseUrl)."/webhooks/source/$type/events/manual";

        return $api;
    }

    return null;
}
function removeAnsiColors($text)
{
    return preg_replace('/\e[[][A-Za-z0-9];?[0-9]*m?/', '', $text);
}

function getTopLevelNetworks(Service|Application $resource)
{
    if ($resource->getMorphClass() === 'App\Models\Service') {
        if ($resource->docker_compose_raw) {
            try {
                $yaml = Yaml::parse($resource->docker_compose_raw);
            } catch (\Exception $e) {
                throw new \Exception($e->getMessage());
            }
            $services = data_get($yaml, 'services');
            $topLevelNetworks = collect(data_get($yaml, 'networks', []));
            $definedNetwork = collect([$resource->uuid]);
            $services = collect($services)->map(function ($service, $_) use ($topLevelNetworks, $definedNetwork) {
                $serviceNetworks = collect(data_get($service, 'networks', []));
                $hasHostNetworkMode = data_get($service, 'network_mode') === 'host' ? true : false;

                // Only add 'networks' key if 'network_mode' is not 'host'
                if (! $hasHostNetworkMode) {
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
                }

                return $service;
            });

            return $topLevelNetworks->keys();
        }
    } elseif ($resource->getMorphClass() === 'App\Models\Application') {
        try {
            $yaml = Yaml::parse($resource->docker_compose_raw);
        } catch (\Exception $e) {
            throw new \Exception($e->getMessage());
        }
        $server = $resource->destination->server;
        $topLevelNetworks = collect(data_get($yaml, 'networks', []));
        $services = data_get($yaml, 'services');
        $definedNetwork = collect([$resource->uuid]);
        $services = collect($services)->map(function ($service, $_) use ($topLevelNetworks, $definedNetwork) {
            $serviceNetworks = collect(data_get($service, 'networks', []));

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

            return $service;
        });

        return $topLevelNetworks->keys();
    }
}
function sourceIsLocal(Stringable $source)
{
    if ($source->startsWith('./') || $source->startsWith('/') || $source->startsWith('~') || $source->startsWith('..') || $source->startsWith('~/') || $source->startsWith('../')) {
        return true;
    }

    return false;
}

function replaceLocalSource(Stringable $source, Stringable $replacedWith)
{
    if ($source->startsWith('.')) {
        $source = $source->replaceFirst('.', $replacedWith->value());
    }
    if ($source->startsWith('~')) {
        $source = $source->replaceFirst('~', $replacedWith->value());
    }
    if ($source->startsWith('..')) {
        $source = $source->replaceFirst('..', $replacedWith->value());
    }
    if ($source->endsWith('/')) {
        $source = $source->replaceLast('/', '');
    }

    return $source;
}

function convertToArray($collection)
{
    if ($collection instanceof Collection) {
        return $collection->map(function ($item) {
            return convertToArray($item);
        })->toArray();
    } elseif ($collection instanceof Stringable) {
        return (string) $collection;
    } elseif (is_array($collection)) {
        return array_map(function ($item) {
            return convertToArray($item);
        }, $collection);
    }

    return $collection;
}

function parseEnvVariable(Str|string $value)
{
    $value = str($value);
    $count = substr_count($value->value(), '_');
    $command = null;
    $forService = null;
    $generatedValue = null;
    $port = null;
    if ($value->startsWith('SERVICE')) {
        if ($count === 2) {
            if ($value->startsWith('SERVICE_FQDN') || $value->startsWith('SERVICE_URL')) {
                // SERVICE_FQDN_UMAMI
                $command = $value->after('SERVICE_')->beforeLast('_');
                $forService = $value->afterLast('_');
            } else {
                // SERVICE_BASE64_UMAMI
                $command = $value->after('SERVICE_')->beforeLast('_');
            }
        }
        if ($count === 3) {
            if ($value->startsWith('SERVICE_FQDN') || $value->startsWith('SERVICE_URL')) {
                // SERVICE_FQDN_UMAMI_1000
                $command = $value->after('SERVICE_')->before('_');
                $forService = $value->after('SERVICE_')->after('_')->before('_');
                $port = $value->afterLast('_');
                if (filter_var($port, FILTER_VALIDATE_INT) === false) {
                    $port = null;
                }
            } else {
                // SERVICE_BASE64_64_UMAMI
                $command = $value->after('SERVICE_')->beforeLast('_');
            }
        }
    }

    return [
        'command' => $command,
        'forService' => $forService,
        'generatedValue' => $generatedValue,
        'port' => $port,
    ];
}
function generateEnvValue(string $command, ?Service $service = null)
{
    switch ($command) {
        case 'PASSWORD':
            $generatedValue = Str::password(symbols: false);
            break;
        case 'PASSWORD_64':
            $generatedValue = Str::password(length: 64, symbols: false);
            break;
            // This is not base64, it's just a random string
        case 'BASE64_64':
            $generatedValue = Str::random(64);
            break;
        case 'BASE64_128':
            $generatedValue = Str::random(128);
            break;
        case 'BASE64':
        case 'BASE64_32':
            $generatedValue = Str::random(32);
            break;
            // This is base64,
        case 'REALBASE64_64':
            $generatedValue = base64_encode(Str::random(64));
            break;
        case 'REALBASE64_128':
            $generatedValue = base64_encode(Str::random(128));
            break;
        case 'REALBASE64':
        case 'REALBASE64_32':
            $generatedValue = base64_encode(Str::random(32));
            break;
        case 'USER':
            $generatedValue = Str::random(16);
            break;
        case 'SUPABASEANON':
            $signingKey = $service->environment_variables()->where('key', 'SERVICE_PASSWORD_JWT')->first();
            if (is_null($signingKey)) {
                return;
            } else {
                $signingKey = $signingKey->value;
            }
            $key = InMemory::plainText($signingKey);
            $algorithm = new Sha256;
            $tokenBuilder = (new Builder(new JoseEncoder, ChainedFormatter::default()));
            $now = new DateTimeImmutable;
            $now = $now->setTime($now->format('H'), $now->format('i'));
            $token = $tokenBuilder
                ->issuedBy('supabase')
                ->issuedAt($now)
                ->expiresAt($now->modify('+100 year'))
                ->withClaim('role', 'anon')
                ->getToken($algorithm, $key);
            $generatedValue = $token->toString();
            break;
        case 'SUPABASESERVICE':
            $signingKey = $service->environment_variables()->where('key', 'SERVICE_PASSWORD_JWT')->first();
            if (is_null($signingKey)) {
                return;
            } else {
                $signingKey = $signingKey->value;
            }
            $key = InMemory::plainText($signingKey);
            $algorithm = new Sha256;
            $tokenBuilder = (new Builder(new JoseEncoder, ChainedFormatter::default()));
            $now = new DateTimeImmutable;
            $now = $now->setTime($now->format('H'), $now->format('i'));
            $token = $tokenBuilder
                ->issuedBy('supabase')
                ->issuedAt($now)
                ->expiresAt($now->modify('+100 year'))
                ->withClaim('role', 'service_role')
                ->getToken($algorithm, $key);
            $generatedValue = $token->toString();
            break;
        default:
            $generatedValue = Str::random(16);
            break;
    }

    return $generatedValue;
}

function getRealtime()
{
    $envDefined = env('PUSHER_PORT');
    if (empty($envDefined)) {
        $url = Url::fromString(Request::getSchemeAndHttpHost());
        $port = $url->getPort();
        if ($port) {
            return '6001';
        } else {
            return null;
        }
    } else {
        return $envDefined;
    }
}

function validate_dns_entry(string $fqdn, Server $server)
{
    // https://www.cloudflare.com/ips-v4/#
    $cloudflare_ips = collect(['173.245.48.0/20', '103.21.244.0/22', '103.22.200.0/22', '103.31.4.0/22', '141.101.64.0/18', '108.162.192.0/18', '190.93.240.0/20', '188.114.96.0/20', '197.234.240.0/22', '198.41.128.0/17', '162.158.0.0/15', '104.16.0.0/13', '172.64.0.0/13', '131.0.72.0/22']);

    $url = Url::fromString($fqdn);
    $host = $url->getHost();
    if (str($host)->contains('sslip.io')) {
        return true;
    }
    $settings = \App\Models\InstanceSettings::get();
    $is_dns_validation_enabled = data_get($settings, 'is_dns_validation_enabled');
    if (! $is_dns_validation_enabled) {
        return true;
    }
    $dns_servers = data_get($settings, 'custom_dns_servers');
    $dns_servers = str($dns_servers)->explode(',');
    if ($server->id === 0) {
        $ip = data_get($settings, 'public_ipv4', data_get($settings, 'public_ipv6', $server->ip));
    } else {
        $ip = $server->ip;
    }
    $found_matching_ip = false;
    $type = \PurplePixie\PhpDns\DNSTypes::NAME_A;
    foreach ($dns_servers as $dns_server) {
        try {
            ray("Checking $host on $dns_server");
            $query = new DNSQuery($dns_server);
            $results = $query->query($host, $type);
            if ($results === false || $query->hasError()) {
                ray('Error: '.$query->getLasterror());
            } else {
                foreach ($results as $result) {
                    if ($result->getType() == $type) {
                        if (ip_match($result->getData(), $cloudflare_ips->toArray(), $match)) {
                            ray("Found match in Cloudflare IPs: $match");
                            $found_matching_ip = true;
                            break;
                        }
                        if ($result->getData() === $ip) {
                            ray($host.' has IP address '.$result->getData());
                            ray($result->getString());
                            $found_matching_ip = true;
                            break;
                        }
                    }
                }
            }
        } catch (\Exception $e) {
        }
    }
    ray("Found match: $found_matching_ip");

    return $found_matching_ip;
}

function ip_match($ip, $cidrs, &$match = null)
{
    foreach ((array) $cidrs as $cidr) {
        [$subnet, $mask] = explode('/', $cidr);
        if (((ip2long($ip) & ($mask = ~((1 << (32 - $mask)) - 1))) == (ip2long($subnet) & $mask))) {
            $match = $cidr;

            return true;
        }
    }

    return false;
}
function checkIfDomainIsAlreadyUsed(Collection|array $domains, ?string $teamId = null, ?string $uuid = null)
{
    if (is_null($teamId)) {
        return response()->json(['error' => 'Team ID is required.'], 400);
    }
    if (is_array($domains)) {
        $domains = collect($domains);
    }

    $domains = $domains->map(function ($domain) {
        if (str($domain)->endsWith('/')) {
            $domain = str($domain)->beforeLast('/');
        }

        return str($domain);
    });
    $applications = Application::ownedByCurrentTeamAPI($teamId)->get(['fqdn', 'uuid']);
    $serviceApplications = ServiceApplication::ownedByCurrentTeamAPI($teamId)->get(['fqdn', 'uuid']);
    if ($uuid) {
        $applications = $applications->filter(fn ($app) => $app->uuid !== $uuid);
        $serviceApplications = $serviceApplications->filter(fn ($app) => $app->uuid !== $uuid);
    }
    $domainFound = false;
    foreach ($applications as $app) {
        if (is_null($app->fqdn)) {
            continue;
        }
        $list_of_domains = collect(explode(',', $app->fqdn))->filter(fn ($fqdn) => $fqdn !== '');
        foreach ($list_of_domains as $domain) {
            if (str($domain)->endsWith('/')) {
                $domain = str($domain)->beforeLast('/');
            }
            $naked_domain = str($domain)->value();
            if ($domains->contains($naked_domain)) {
                $domainFound = true;
                break;
            }
        }
    }
    if ($domainFound) {
        return true;
    }
    foreach ($serviceApplications as $app) {
        if (str($app->fqdn)->isEmpty()) {
            continue;
        }
        $list_of_domains = collect(explode(',', $app->fqdn))->filter(fn ($fqdn) => $fqdn !== '');
        foreach ($list_of_domains as $domain) {
            if (str($domain)->endsWith('/')) {
                $domain = str($domain)->beforeLast('/');
            }
            $naked_domain = str($domain)->value();
            if ($domains->contains($naked_domain)) {
                $domainFound = true;
                break;
            }
        }
    }
    if ($domainFound) {
        return true;
    }
    $settings = \App\Models\InstanceSettings::get();
    if (data_get($settings, 'fqdn')) {
        $domain = data_get($settings, 'fqdn');
        if (str($domain)->endsWith('/')) {
            $domain = str($domain)->beforeLast('/');
        }
        $naked_domain = str($domain)->value();
        if ($domains->contains($naked_domain)) {
            return true;
        }
    }
}
function check_domain_usage(ServiceApplication|Application|null $resource = null, ?string $domain = null)
{
    if ($resource) {
        if ($resource->getMorphClass() === 'App\Models\Application' && $resource->build_pack === 'dockercompose') {
            $domains = data_get(json_decode($resource->docker_compose_domains, true), '*.domain');
            $domains = collect($domains);
        } else {
            $domains = collect($resource->fqdns);
        }
    } elseif ($domain) {
        $domains = collect($domain);
    } else {
        throw new \RuntimeException('No resource or FQDN provided.');
    }
    $domains = $domains->map(function ($domain) {
        if (str($domain)->endsWith('/')) {
            $domain = str($domain)->beforeLast('/');
        }

        return str($domain);
    });
    $apps = Application::all();
    foreach ($apps as $app) {
        $list_of_domains = collect(explode(',', $app->fqdn))->filter(fn ($fqdn) => $fqdn !== '');
        foreach ($list_of_domains as $domain) {
            if (str($domain)->endsWith('/')) {
                $domain = str($domain)->beforeLast('/');
            }
            $naked_domain = str($domain)->value();
            if ($domains->contains($naked_domain)) {
                if (data_get($resource, 'uuid')) {
                    if ($resource->uuid !== $app->uuid) {
                        throw new \RuntimeException("Domain $naked_domain is already in use by another resource called: <br><br>{$app->name}.");
                    }
                } elseif ($domain) {
                    throw new \RuntimeException("Domain $naked_domain is already in use by another resource called: <br><br>{$app->name}.");
                }
            }
        }
    }
    $apps = ServiceApplication::all();
    foreach ($apps as $app) {
        $list_of_domains = collect(explode(',', $app->fqdn))->filter(fn ($fqdn) => $fqdn !== '');
        foreach ($list_of_domains as $domain) {
            if (str($domain)->endsWith('/')) {
                $domain = str($domain)->beforeLast('/');
            }
            $naked_domain = str($domain)->value();
            if ($domains->contains($naked_domain)) {
                if (data_get($resource, 'uuid')) {
                    if ($resource->uuid !== $app->uuid) {
                        throw new \RuntimeException("Domain $naked_domain is already in use by another resource called: <br><br>{$app->name}.");
                    }
                } elseif ($domain) {
                    throw new \RuntimeException("Domain $naked_domain is already in use by another resource called: <br><br>{$app->name}.");
                }
            }
        }
    }
    if ($resource) {
        $settings = \App\Models\InstanceSettings::get();
        if (data_get($settings, 'fqdn')) {
            $domain = data_get($settings, 'fqdn');
            if (str($domain)->endsWith('/')) {
                $domain = str($domain)->beforeLast('/');
            }
            $naked_domain = str($domain)->value();
            if ($domains->contains($naked_domain)) {
                throw new \RuntimeException("Domain $naked_domain is already in use by this Coolify instance.");
            }
        }
    }
}

function parseCommandsByLineForSudo(Collection $commands, Server $server): array
{
    $commands = $commands->map(function ($line) {
        if (! str($line)->startsWith('cd') && ! str($line)->startsWith('command') && ! str($line)->startsWith('echo') && ! str($line)->startsWith('true')) {
            return "sudo $line";
        }

        return $line;
    });
    $commands = $commands->map(function ($line) use ($server) {
        if (Str::startsWith($line, 'sudo mkdir -p')) {
            return "$line && sudo chown -R $server->user:$server->user ".Str::after($line, 'sudo mkdir -p').' && sudo chmod -R o-rwx '.Str::after($line, 'sudo mkdir -p');
        }

        return $line;
    });
    $commands = $commands->map(function ($line) {
        $line = str($line);
        if (str($line)->contains('$(')) {
            $line = $line->replace('$(', '$(sudo ');
        }
        if (str($line)->contains('||')) {
            $line = $line->replace('||', '|| sudo');
        }
        if (str($line)->contains('&&')) {
            $line = $line->replace('&&', '&& sudo');
        }
        if (str($line)->contains(' | ')) {
            $line = $line->replace(' | ', ' | sudo ');
        }

        return $line->value();
    });

    return $commands->toArray();
}
function parseLineForSudo(string $command, Server $server): string
{
    if (! str($command)->startSwith('cd') && ! str($command)->startSwith('command')) {
        $command = "sudo $command";
    }
    if (Str::startsWith($command, 'sudo mkdir -p')) {
        $command = "$command && sudo chown -R $server->user:$server->user ".Str::after($command, 'sudo mkdir -p').' && sudo chmod -R o-rwx '.Str::after($command, 'sudo mkdir -p');
    }
    if (str($command)->contains('$(') || str($command)->contains('`')) {
        $command = str($command)->replace('$(', '$(sudo ')->replace('`', '`sudo ')->value();
    }
    if (str($command)->contains('||')) {
        $command = str($command)->replace('||', '|| sudo ')->value();
    }
    if (str($command)->contains('&&')) {
        $command = str($command)->replace('&&', '&& sudo ')->value();
    }

    return $command;
}

function get_public_ips()
{
    try {
        echo "Refreshing public ips!\n";
        $settings = \App\Models\InstanceSettings::get();
        [$first, $second] = Process::concurrently(function (Pool $pool) {
            $pool->path(__DIR__)->command('curl -4s https://ifconfig.io');
            $pool->path(__DIR__)->command('curl -6s https://ifconfig.io');
        });
        $ipv4 = $first->output();
        if ($ipv4) {
            $ipv4 = trim($ipv4);
            $validate_ipv4 = filter_var($ipv4, FILTER_VALIDATE_IP);
            if ($validate_ipv4 == false) {
                echo "Invalid ipv4: $ipv4\n";

                return;
            }
            $settings->update(['public_ipv4' => $ipv4]);
        }
        $ipv6 = $second->output();
        if ($ipv6) {
            $ipv6 = trim($ipv6);
            $validate_ipv6 = filter_var($ipv6, FILTER_VALIDATE_IP);
            if ($validate_ipv6 == false) {
                echo "Invalid ipv6: $ipv6\n";

                return;
            }
            $settings->update(['public_ipv6' => $ipv6]);
        }
    } catch (\Throwable $e) {
        echo "Error: {$e->getMessage()}\n";
    }
}

function isAnyDeploymentInprogress()
{
    // Only use it in the deployment script
    $count = ApplicationDeploymentQueue::whereIn('status', [ApplicationDeploymentStatus::IN_PROGRESS, ApplicationDeploymentStatus::QUEUED])->count();
    if ($count > 0) {
        echo "There are $count deployments in progress. Exiting...\n";
        exit(1);
    }
    echo "No deployments in progress.\n";
    exit(0);
}

function generateSentinelToken()
{
    $token = Str::random(64);

    return $token;
}

function isBase64Encoded($strValue)
{
    return base64_encode(base64_decode($strValue, true)) === $strValue;
}
function customApiValidator(Collection|array $item, array $rules)
{
    if (is_array($item)) {
        $item = collect($item);
    }

    return Validator::make($item->toArray(), $rules, [
        'required' => 'This field is required.',
    ]);
}

function parseServiceVolumes($serviceVolumes, $resource, $topLevelVolumes, $pull_request_id = 0)
{
    $serviceVolumes = $serviceVolumes->map(function ($volume) use ($resource, $topLevelVolumes, $pull_request_id) {
        $type = null;
        $source = null;
        $target = null;
        $content = null;
        $isDirectory = false;
        if (is_string($volume)) {
            $source = str($volume)->before(':');
            $target = str($volume)->after(':')->beforeLast(':');
            $foundConfig = $resource->fileStorages()->whereMountPath($target)->first();
            if ($source->startsWith('./') || $source->startsWith('/') || $source->startsWith('~')) {
                $type = str('bind');
                if ($foundConfig) {
                    $contentNotNull = data_get($foundConfig, 'content');
                    if ($contentNotNull) {
                        $content = $contentNotNull;
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
            $foundConfig = $resource->fileStorages()->whereMountPath($target)->first();
            if ($foundConfig) {
                $contentNotNull = data_get($foundConfig, 'content');
                if ($contentNotNull) {
                    $content = $contentNotNull;
                }
                $isDirectory = data_get($foundConfig, 'is_directory');
            } else {
                $isDirectory = (bool) data_get($volume, 'isDirectory', null) || (bool) data_get($volume, 'is_directory', null);
                if ((is_null($isDirectory) || ! $isDirectory) && is_null($content)) {
                    // if isDirectory is not set (or false) & content is also not set, we assume it is a directory
                    ray('setting isDirectory to true');
                    $isDirectory = true;
                }
            }

        }
        if ($type?->value() === 'bind') {
            if ($source->value() === '/var/run/docker.sock') {
                return $volume;
            }
            if ($source->value() === '/tmp' || $source->value() === '/tmp/') {
                return $volume;
            }
            if (get_class($resource) === "App\Models\Application") {
                $dir = base_configuration_dir().'/applications/'.$resource->uuid;
            } else {
                $dir = base_configuration_dir().'/services/'.$resource->service->uuid;
            }

            if ($source->startsWith('.')) {
                $source = $source->replaceFirst('.', $dir);
            }
            if ($source->startsWith('~')) {
                $source = $source->replaceFirst('~', $dir);
            }
            if ($pull_request_id !== 0) {
                $source = $source."-pr-$pull_request_id";
            }
            if (! $resource?->settings?->is_preserve_repository_enabled || $foundConfig?->is_based_on_git) {
                LocalFileVolume::updateOrCreate(
                    [
                        'mount_path' => $target,
                        'resource_id' => $resource->id,
                        'resource_type' => get_class($resource),
                    ],
                    [
                        'fs_path' => $source,
                        'mount_path' => $target,
                        'content' => $content,
                        'is_directory' => $isDirectory,
                        'resource_id' => $resource->id,
                        'resource_type' => get_class($resource),
                    ]
                );
            }
        } elseif ($type->value() === 'volume') {
            if ($topLevelVolumes->has($source->value())) {
                $v = $topLevelVolumes->get($source->value());
                if (data_get($v, 'driver_opts.type') === 'cifs') {
                    return $volume;
                }
            }
            $slugWithoutUuid = Str::slug($source, '-');
            if (get_class($resource) === "App\Models\Application") {
                $name = "{$resource->uuid}_{$slugWithoutUuid}";
            } else {
                $name = "{$resource->service->uuid}_{$slugWithoutUuid}";
            }
            if (is_string($volume)) {
                $source = str($volume)->before(':');
                $target = str($volume)->after(':')->beforeLast(':');
                $source = $name;
                $volume = "$source:$target";
            } elseif (is_array($volume)) {
                data_set($volume, 'source', $name);
            }
            $topLevelVolumes->put($name, [
                'name' => $name,
            ]);
            LocalPersistentVolume::updateOrCreate(
                [
                    'mount_path' => $target,
                    'resource_id' => $resource->id,
                    'resource_type' => get_class($resource),
                ],
                [
                    'name' => $name,
                    'mount_path' => $target,
                    'resource_id' => $resource->id,
                    'resource_type' => get_class($resource),
                ]
            );
        }
        dispatch(new ServerFilesFromServerJob($resource));

        return $volume;
    });

    return [
        'serviceVolumes' => $serviceVolumes,
        'topLevelVolumes' => $topLevelVolumes,
    ];
}
