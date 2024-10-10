<?php

use App\Enums\ApplicationDeploymentStatus;
use App\Enums\ProxyTypes;
use App\Jobs\ServerFilesFromServerJob;
use App\Models\Application;
use App\Models\ApplicationDeploymentQueue;
use App\Models\ApplicationPreview;
use App\Models\EnvironmentVariable;
use App\Models\InstanceSettings;
use App\Models\LocalFileVolume;
use App\Models\LocalPersistentVolume;
use App\Models\Server;
use App\Models\Service;
use App\Models\ServiceApplication;
use App\Models\ServiceDatabase;
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
        $settings = instanceSettings();
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
    $settings = instanceSettings();
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
    $settings = instanceSettings();
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

function isProduction(): bool
{
    return ! isDev();
}
function isDev(): bool
{
    return config('app.env') === 'local';
}

function isCloud(): bool
{
    return ! config('coolify.self_hosted');
}

function translate_cron_expression($expression_to_validate): string
{
    if (isset(VALID_CRON_STRINGS[$expression_to_validate])) {
        return VALID_CRON_STRINGS[$expression_to_validate];
    }

    return $expression_to_validate;
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
    $settings = instanceSettings();
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

function generateFqdn(Server $server, string $random, bool $forceHttps = false): string
{
    $wildcard = data_get($server, 'settings.wildcard_domain');
    if (is_null($wildcard) || $wildcard === '') {
        $wildcard = sslip($server);
    }
    $url = Url::fromString($wildcard);
    $host = $url->getHost();
    $path = $url->getPath() === '/' ? '' : $url->getPath();
    $scheme = $url->getScheme();
    if ($forceHttps) {
        $scheme = 'https';
    }
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
    // ipv6
    if (str($server->ip)->contains(':')) {
        $ipv6 = str($server->ip)->replace(':', '-');

        return "http://{$ipv6}.sslip.io";
    }

    return "http://{$server->ip}.sslip.io";
}

function get_service_templates(bool $force = false): Collection
{
    if (isDev()) {
        $services = File::get(base_path('templates/service-templates.json'));

        return collect(json_decode($services))->sortKeys();
    }
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
                                if (is_string($networkDetails) || is_int($networkDetails)) {
                                    $topLevelNetworks->put($networkDetails, null);
                                }
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
                        if (is_string($networkDetails) || is_int($networkDetails)) {
                            $topLevelNetworks->put($networkDetails, null);
                        }
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
    if ($source->endsWith('/') && $source->value() !== '/') {
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

function parseCommandFromMagicEnvVariable(Str|string $key): Stringable
{
    $value = str($key);
    $count = substr_count($value->value(), '_');
    if ($count === 2) {
        if ($value->startsWith('SERVICE_FQDN') || $value->startsWith('SERVICE_URL')) {
            // SERVICE_FQDN_UMAMI
            $command = $value->after('SERVICE_')->beforeLast('_');
        } else {
            // SERVICE_BASE64_UMAMI
            $command = $value->after('SERVICE_')->beforeLast('_');
        }
    }
    if ($count === 3) {
        if ($value->startsWith('SERVICE_FQDN') || $value->startsWith('SERVICE_URL')) {
            // SERVICE_FQDN_UMAMI_1000
            $command = $value->after('SERVICE_')->before('_');
        } else {
            // SERVICE_BASE64_64_UMAMI
            $command = $value->after('SERVICE_')->beforeLast('_');
        }
    }

    return str($command);
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
                ray($command);
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
function generateEnvValue(string $command, Service|Application|null $service = null)
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
            // $generatedValue = Str::random(16);
            $generatedValue = null;
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
    $settings = instanceSettings();
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
    $settings = instanceSettings();
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
                        throw new \RuntimeException("Domain $naked_domain is already in use by another resource: <br><br>Link: <a class='underline' target='_blank' href='{$app->link()}'>{$app->name}</a>");
                    }
                } elseif ($domain) {
                    throw new \RuntimeException("Domain $naked_domain is already in use by another resource: <br><br>Link: <a class='underline' target='_blank' href='{$app->link()}'>{$app->name}</a>");
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
                        throw new \RuntimeException("Domain $naked_domain is already in use by another resource: <br><br>Link: <a class='underline' target='_blank' href='{$app->service->link()}'>{$app->service->name}</a>");
                    }
                } elseif ($domain) {
                    throw new \RuntimeException("Domain $naked_domain is already in use by another resource: <br><br>Link: <a class='underline' target='_blank' href='{$app->service->link()}'>{$app->service->name}</a>");
                }
            }
        }
    }
    if ($resource) {
        $settings = instanceSettings();
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
        if (
            ! str(trim($line))->startsWith([
                'cd',
                'command',
                'echo',
                'true',
                'if',
                'fi',
            ])
        ) {
            return "sudo $line";
        }

        if (str(trim($line))->startsWith('if')) {
            return str_replace('if', 'if sudo', $line);
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
            InstanceSettings::get()->update(['public_ipv4' => $ipv4]);
        }
    } catch (\Exception $e) {
        echo "Error: {$e->getMessage()}\n";
    }
    try {
        $ipv6 = $second->output();
        if ($ipv6) {
            $ipv6 = trim($ipv6);
            $validate_ipv6 = filter_var($ipv6, FILTER_VALIDATE_IP);
            if ($validate_ipv6 == false) {
                echo "Invalid ipv6: $ipv6\n";

                return;
            }
            InstanceSettings::get()->update(['public_ipv6' => $ipv6]);
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

function parseDockerComposeFile(Service|Application $resource, bool $isNew = false, int $pull_request_id = 0, ?int $preview_id = null)
{
    if ($resource->getMorphClass() === 'App\Models\Service') {
        if ($resource->docker_compose_raw) {
            try {
                $yaml = Yaml::parse($resource->docker_compose_raw);
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
            if (is_null($resource->destination)) {
                $destination = $resource->server->destinations()->first();
                if ($destination) {
                    $resource->destination()->associate($destination);
                    $resource->save();
                }
            }
            $definedNetwork = collect([$resource->uuid]);
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
            $services = collect($services)->map(function ($service, $serviceName) use ($topLevelVolumes, $topLevelNetworks, $definedNetwork, $isNew, $generatedServiceFQDNS, $resource, $allServices) {
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

                $containerName = "$serviceName-{$resource->uuid}";

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
                            'service_id' => $resource->id,
                        ]);
                    } else {
                        $savedService = ServiceDatabase::where([
                            'name' => $serviceName,
                            'service_id' => $resource->id,
                        ])->first();
                    }
                } else {
                    if ($isNew) {
                        $savedService = ServiceApplication::create([
                            'name' => $serviceName,
                            'image' => $image,
                            'service_id' => $resource->id,
                        ]);
                    } else {
                        $savedService = ServiceApplication::where([
                            'name' => $serviceName,
                            'service_id' => $resource->id,
                        ])->first();
                    }
                }
                if (is_null($savedService)) {
                    if ($isDatabase) {
                        $savedService = ServiceDatabase::create([
                            'name' => $serviceName,
                            'image' => $image,
                            'service_id' => $resource->id,
                        ]);
                    } else {
                        $savedService = ServiceApplication::create([
                            'name' => $serviceName,
                            'image' => $image,
                            'service_id' => $resource->id,
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
                            if (is_string($networkDetails) || is_int($networkDetails)) {
                                $topLevelNetworks->put($networkDetails, null);
                            }
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
                    $serviceVolumes = $serviceVolumes->map(function ($volume) use ($savedService, $topLevelVolumes) {
                        $type = null;
                        $source = null;
                        $target = null;
                        $content = null;
                        $isDirectory = false;
                        if (is_string($volume)) {
                            $source = str($volume)->before(':');
                            $target = str($volume)->after(':')->beforeLast(':');
                            if ($source->startsWith('./') || $source->startsWith('/') || $source->startsWith('~')) {
                                $type = str('bind');
                                // By default, we cannot determine if the bind is a directory or not, so we set it to directory
                                $isDirectory = true;
                            } else {
                                $type = str('volume');
                            }
                        } elseif (is_array($volume)) {
                            $type = data_get_str($volume, 'type');
                            $source = data_get_str($volume, 'source');
                            $target = data_get_str($volume, 'target');
                            $content = data_get($volume, 'content');
                            $isDirectory = (bool) data_get($volume, 'isDirectory', null) || (bool) data_get($volume, 'is_directory', null);
                            $foundConfig = $savedService->fileStorages()->whereMountPath($target)->first();
                            if ($foundConfig) {
                                $contentNotNull = data_get($foundConfig, 'content');
                                if ($contentNotNull) {
                                    $content = $contentNotNull;
                                }
                                $isDirectory = (bool) data_get($volume, 'isDirectory', null) || (bool) data_get($volume, 'is_directory', null);
                            }
                            if (is_null($isDirectory) && is_null($content)) {
                                // if isDirectory is not set & content is also not set, we assume it is a directory
                                ray('setting isDirectory to true');
                                $isDirectory = true;
                            }
                        }
                        if ($type?->value() === 'bind') {
                            if ($source->value() === '/var/run/docker.sock') {
                                return $volume;
                            }
                            if ($source->value() === '/tmp' || $source->value() === '/tmp/') {
                                return $volume;
                            }
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
                        } elseif ($type->value() === 'volume') {
                            if ($topLevelVolumes->has($source->value())) {
                                $v = $topLevelVolumes->get($source->value());
                                if (data_get($v, 'driver_opts.type') === 'cifs') {
                                    return $volume;
                                }
                            }
                            $slugWithoutUuid = Str::slug($source, '-');
                            $name = "{$savedService->service->uuid}_{$slugWithoutUuid}";
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

                        return $volume;
                    });
                    data_set($service, 'volumes', $serviceVolumes->toArray());
                }

                // convert - SESSION_SECRET: 123 to - SESSION_SECRET=123
                $convertedServiceVariables = collect([]);
                foreach ($serviceVariables as $variableName => $variable) {
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
                $serviceVariables = $convertedServiceVariables;
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
                            $fqdn = generateFqdn($resource->server, "{$name->value()}-{$resource->uuid}");
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
                                'service_id' => $resource->id,
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
                                'service_id' => $resource->id,
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
                            'service_id' => $resource->id,
                        ])->first();
                        $value = replaceVariables($value);
                        $key = $value;
                        if ($value->startsWith('SERVICE_')) {
                            $foundEnv = EnvironmentVariable::where([
                                'key' => $key,
                                'service_id' => $resource->id,
                            ])->first();
                            ['command' => $command, 'forService' => $forService, 'generatedValue' => $generatedValue, 'port' => $port] = parseEnvVariable($value);
                            if (! is_null($command)) {
                                if ($command?->value() === 'FQDN' || $command?->value() === 'URL') {
                                    if (Str::lower($forService) === $serviceName) {
                                        $fqdn = generateFqdn($resource->server, $containerName);
                                    } else {
                                        $fqdn = generateFqdn($resource->server, Str::lower($forService).'-'.$resource->uuid);
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
                                            'service_id' => $resource->id,
                                            'is_preview' => false,
                                        ]);
                                    }
                                    if (! $isDatabase) {
                                        if ($command->value() === 'FQDN' && is_null($savedService->fqdn) && ! $foundEnv) {
                                            $savedService->fqdn = $fqdn;
                                            $savedService->save();
                                        }
                                        // Caddy needs exact port in some cases.
                                        if ($predefinedPort && ! $key->endsWith("_{$predefinedPort}") && $command?->value() === 'FQDN' && $resource->server->proxyType() === 'CADDY') {
                                            $fqdns_exploded = str($savedService->fqdn)->explode(',');
                                            if ($fqdns_exploded->count() > 1) {
                                                continue;
                                            }
                                            $env = EnvironmentVariable::where([
                                                'key' => $key,
                                                'service_id' => $resource->id,
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
                                    $generatedValue = generateEnvValue($command, $resource);
                                    if (! $foundEnv) {
                                        EnvironmentVariable::create([
                                            'key' => $key,
                                            'value' => $generatedValue,
                                            'is_build_time' => false,
                                            'service_id' => $resource->id,
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
                                'service_id' => $resource->id,
                            ])->first();
                            if ($foundEnv) {
                                $defaultValue = data_get($foundEnv, 'value');
                            }
                            EnvironmentVariable::updateOrCreate([
                                'key' => $key,
                                'service_id' => $resource->id,
                            ], [
                                'value' => $defaultValue,
                                'is_build_time' => false,
                                'service_id' => $resource->id,
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
                $defaultLabels = defaultLabels($resource->id, $containerName, type: 'service', subType: $isDatabase ? 'database' : 'application', subId: $savedService->id);
                $serviceLabels = $serviceLabels->merge($defaultLabels);
                if (! $isDatabase && $fqdns->count() > 0) {
                    if ($fqdns) {
                        $shouldGenerateLabelsExactly = $resource->server->settings->generate_exact_labels;
                        if ($shouldGenerateLabelsExactly) {
                            switch ($resource->server->proxyType()) {
                                case ProxyTypes::TRAEFIK->value:
                                    $serviceLabels = $serviceLabels->merge(fqdnLabelsForTraefik(
                                        uuid: $resource->uuid,
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
                                        network: $resource->destination->network,
                                        uuid: $resource->uuid,
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
                                uuid: $resource->uuid,
                                domains: $fqdns,
                                is_force_https_enabled: true,
                                serviceLabels: $serviceLabels,
                                is_gzip_enabled: $savedService->isGzipEnabled(),
                                is_stripprefix_enabled: $savedService->isStripprefixEnabled(),
                                service_name: $serviceName,
                                image: data_get($service, 'image')
                            ));
                            $serviceLabels = $serviceLabels->merge(fqdnLabelsForCaddy(
                                network: $resource->destination->network,
                                uuid: $resource->uuid,
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
                if ($resource->server->isLogDrainEnabled() && $savedService->isLogDrainEnabled()) {
                    data_set($service, 'logging', generate_fluentd_configuration());
                }
                if ($serviceLabels->count() > 0) {
                    if ($resource->is_container_label_escape_enabled) {
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

            $envs_from_coolify = $resource->environment_variables()->get();
            $services = collect($services)->map(function ($service, $serviceName) use ($resource, $envs_from_coolify) {
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
                $parsedServiceVariables->put('COOLIFY_CONTAINER_NAME', "$serviceName-{$resource->uuid}");

                // TODO: move this in a shared function
                if (! $parsedServiceVariables->has('COOLIFY_APP_NAME')) {
                    $parsedServiceVariables->put('COOLIFY_APP_NAME', "\"{$resource->name}\"");
                }
                if (! $parsedServiceVariables->has('COOLIFY_SERVER_IP')) {
                    $parsedServiceVariables->put('COOLIFY_SERVER_IP', "\"{$resource->destination->server->ip}\"");
                }
                if (! $parsedServiceVariables->has('COOLIFY_ENVIRONMENT_NAME')) {
                    $parsedServiceVariables->put('COOLIFY_ENVIRONMENT_NAME', "\"{$resource->environment->name}\"");
                }
                if (! $parsedServiceVariables->has('COOLIFY_PROJECT_NAME')) {
                    $parsedServiceVariables->put('COOLIFY_PROJECT_NAME', "\"{$resource->project()->name}\"");
                }

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
            $resource->docker_compose_raw = Yaml::dump($yaml, 10, 2);
            $resource->docker_compose = Yaml::dump($finalServices, 10, 2);

            $resource->save();
            $resource->saveComposeConfigs();

            return collect($finalServices);
        } else {
            return collect([]);
        }
    } elseif ($resource->getMorphClass() === 'App\Models\Application') {
        try {
            $yaml = Yaml::parse($resource->docker_compose_raw);
        } catch (\Exception $e) {
            return;
        }
        $server = $resource->destination->server;
        $topLevelVolumes = collect(data_get($yaml, 'volumes', []));
        if ($pull_request_id !== 0) {
            $topLevelVolumes = collect([]);
        }

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

        $topLevelNetworks = collect(data_get($yaml, 'networks', []));
        $topLevelConfigs = collect(data_get($yaml, 'configs', []));
        $topLevelSecrets = collect(data_get($yaml, 'secrets', []));
        $services = data_get($yaml, 'services');

        $generatedServiceFQDNS = collect([]);
        if (is_null($resource->destination)) {
            $destination = $server->destinations()->first();
            if ($destination) {
                $resource->destination()->associate($destination);
                $resource->save();
            }
        }
        $definedNetwork = collect([$resource->uuid]);
        if ($pull_request_id !== 0) {
            $definedNetwork = collect(["{$resource->uuid}-$pull_request_id"]);
        }
        $services = collect($services)->map(function ($service, $serviceName) use ($topLevelVolumes, $topLevelNetworks, $definedNetwork, $isNew, $generatedServiceFQDNS, $resource, $server, $pull_request_id, $preview_id) {
            $serviceVolumes = collect(data_get($service, 'volumes', []));
            $servicePorts = collect(data_get($service, 'ports', []));
            $serviceNetworks = collect(data_get($service, 'networks', []));
            $serviceVariables = collect(data_get($service, 'environment', []));
            $serviceDependencies = collect(data_get($service, 'depends_on', []));
            $serviceLabels = collect(data_get($service, 'labels', []));
            $serviceBuildVariables = collect(data_get($service, 'build.args', []));
            $serviceVariables = $serviceVariables->merge($serviceBuildVariables);
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

            $baseName = generateApplicationContainerName($resource, $pull_request_id);
            $containerName = "$serviceName-$baseName";
            if ($resource->compose_parsing_version === '1') {
                if (count($serviceVolumes) > 0) {
                    $serviceVolumes = $serviceVolumes->map(function ($volume) use ($resource, $topLevelVolumes, $pull_request_id) {
                        if (is_string($volume)) {
                            $volume = str($volume);
                            if ($volume->contains(':') && ! $volume->startsWith('/')) {
                                $name = $volume->before(':');
                                $mount = $volume->after(':');
                                if ($name->startsWith('.') || $name->startsWith('~')) {
                                    $dir = base_configuration_dir().'/applications/'.$resource->uuid;
                                    if ($name->startsWith('.')) {
                                        $name = $name->replaceFirst('.', $dir);
                                    }
                                    if ($name->startsWith('~')) {
                                        $name = $name->replaceFirst('~', $dir);
                                    }
                                    if ($pull_request_id !== 0) {
                                        $name = $name."-pr-$pull_request_id";
                                    }
                                    $volume = str("$name:$mount");
                                } else {
                                    if ($pull_request_id !== 0) {
                                        $name = $name."-pr-$pull_request_id";
                                        $volume = str("$name:$mount");
                                        if ($topLevelVolumes->has($name)) {
                                            $v = $topLevelVolumes->get($name);
                                            if (data_get($v, 'driver_opts.type') === 'cifs') {
                                                // Do nothing
                                            } else {
                                                if (is_null(data_get($v, 'name'))) {
                                                    data_set($v, 'name', $name);
                                                    data_set($topLevelVolumes, $name, $v);
                                                }
                                            }
                                        } else {
                                            $topLevelVolumes->put($name, [
                                                'name' => $name,
                                            ]);
                                        }
                                    } else {
                                        if ($topLevelVolumes->has($name->value())) {
                                            $v = $topLevelVolumes->get($name->value());
                                            if (data_get($v, 'driver_opts.type') === 'cifs') {
                                                // Do nothing
                                            } else {
                                                if (is_null(data_get($v, 'name'))) {
                                                    data_set($topLevelVolumes, $name->value(), $v);
                                                }
                                            }
                                        } else {
                                            $topLevelVolumes->put($name->value(), [
                                                'name' => $name->value(),
                                            ]);
                                        }
                                    }
                                }
                            } else {
                                if ($volume->startsWith('/')) {
                                    $name = $volume->before(':');
                                    $mount = $volume->after(':');
                                    if ($pull_request_id !== 0) {
                                        $name = $name."-pr-$pull_request_id";
                                    }
                                    $volume = str("$name:$mount");
                                }
                            }
                        } elseif (is_array($volume)) {
                            $source = data_get($volume, 'source');
                            $target = data_get($volume, 'target');
                            $read_only = data_get($volume, 'read_only');
                            if ($source && $target) {
                                if ((str($source)->startsWith('.') || str($source)->startsWith('~'))) {
                                    $dir = base_configuration_dir().'/applications/'.$resource->uuid;
                                    if (str($source, '.')) {
                                        $source = str($source)->replaceFirst('.', $dir);
                                    }
                                    if (str($source, '~')) {
                                        $source = str($source)->replaceFirst('~', $dir);
                                    }
                                    if ($pull_request_id !== 0) {
                                        $source = $source."-pr-$pull_request_id";
                                    }
                                    if ($read_only) {
                                        data_set($volume, 'source', $source.':'.$target.':ro');
                                    } else {
                                        data_set($volume, 'source', $source.':'.$target);
                                    }
                                } else {
                                    if ($pull_request_id !== 0) {
                                        $source = $source."-pr-$pull_request_id";
                                    }
                                    if ($read_only) {
                                        data_set($volume, 'source', $source.':'.$target.':ro');
                                    } else {
                                        data_set($volume, 'source', $source.':'.$target);
                                    }
                                    if (! str($source)->startsWith('/')) {
                                        if ($topLevelVolumes->has($source)) {
                                            $v = $topLevelVolumes->get($source);
                                            if (data_get($v, 'driver_opts.type') === 'cifs') {
                                                // Do nothing
                                            } else {
                                                if (is_null(data_get($v, 'name'))) {
                                                    data_set($v, 'name', $source);
                                                    data_set($topLevelVolumes, $source, $v);
                                                }
                                            }
                                        } else {
                                            $topLevelVolumes->put($source, [
                                                'name' => $source,
                                            ]);
                                        }
                                    }
                                }
                            }
                        }
                        if (is_array($volume)) {
                            return data_get($volume, 'source');
                        }

                        return $volume->value();
                    });
                    data_set($service, 'volumes', $serviceVolumes->toArray());
                }
            } elseif ($resource->compose_parsing_version === '2') {
                if (count($serviceVolumes) > 0) {
                    $serviceVolumes = $serviceVolumes->map(function ($volume) use ($resource, $topLevelVolumes, $pull_request_id) {
                        if (is_string($volume)) {
                            $volume = str($volume);
                            if ($volume->contains(':') && ! $volume->startsWith('/')) {
                                $name = $volume->before(':');
                                $mount = $volume->after(':');
                                if ($name->startsWith('.') || $name->startsWith('~')) {
                                    $dir = base_configuration_dir().'/applications/'.$resource->uuid;
                                    if ($name->startsWith('.')) {
                                        $name = $name->replaceFirst('.', $dir);
                                    }
                                    if ($name->startsWith('~')) {
                                        $name = $name->replaceFirst('~', $dir);
                                    }
                                    if ($pull_request_id !== 0) {
                                        $name = $name."-pr-$pull_request_id";
                                    }
                                    $volume = str("$name:$mount");
                                } else {
                                    if ($pull_request_id !== 0) {
                                        $uuid = $resource->uuid;
                                        $name = $uuid."-$name-pr-$pull_request_id";
                                        $volume = str("$name:$mount");
                                        if ($topLevelVolumes->has($name)) {
                                            $v = $topLevelVolumes->get($name);
                                            if (data_get($v, 'driver_opts.type') === 'cifs') {
                                                // Do nothing
                                            } else {
                                                if (is_null(data_get($v, 'name'))) {
                                                    data_set($v, 'name', $name);
                                                    data_set($topLevelVolumes, $name, $v);
                                                }
                                            }
                                        } else {
                                            $topLevelVolumes->put($name, [
                                                'name' => $name,
                                            ]);
                                        }
                                    } else {
                                        $uuid = $resource->uuid;
                                        $name = str($uuid."-$name");
                                        $volume = str("$name:$mount");
                                        if ($topLevelVolumes->has($name->value())) {
                                            $v = $topLevelVolumes->get($name->value());
                                            if (data_get($v, 'driver_opts.type') === 'cifs') {
                                                // Do nothing
                                            } else {
                                                if (is_null(data_get($v, 'name'))) {
                                                    data_set($topLevelVolumes, $name->value(), $v);
                                                }
                                            }
                                        } else {
                                            $topLevelVolumes->put($name->value(), [
                                                'name' => $name->value(),
                                            ]);
                                        }
                                    }
                                }
                            } else {
                                if ($volume->startsWith('/')) {
                                    $name = $volume->before(':');
                                    $mount = $volume->after(':');
                                    if ($pull_request_id !== 0) {
                                        $name = $name."-pr-$pull_request_id";
                                    }
                                    $volume = str("$name:$mount");
                                }
                            }
                        } elseif (is_array($volume)) {
                            $source = data_get($volume, 'source');
                            $target = data_get($volume, 'target');
                            $read_only = data_get($volume, 'read_only');
                            if ($source && $target) {
                                $uuid = $resource->uuid;
                                if ((str($source)->startsWith('.') || str($source)->startsWith('~') || str($source)->startsWith('/'))) {
                                    $dir = base_configuration_dir().'/applications/'.$resource->uuid;
                                    if (str($source, '.')) {
                                        $source = str($source)->replaceFirst('.', $dir);
                                    }
                                    if (str($source, '~')) {
                                        $source = str($source)->replaceFirst('~', $dir);
                                    }
                                    if ($read_only) {
                                        data_set($volume, 'source', $source.':'.$target.':ro');
                                    } else {
                                        data_set($volume, 'source', $source.':'.$target);
                                    }
                                } else {
                                    if ($pull_request_id === 0) {
                                        $source = $uuid."-$source";
                                    } else {
                                        $source = $uuid."-$source-pr-$pull_request_id";
                                    }
                                    if ($read_only) {
                                        data_set($volume, 'source', $source.':'.$target.':ro');
                                    } else {
                                        data_set($volume, 'source', $source.':'.$target);
                                    }
                                    if (! str($source)->startsWith('/')) {
                                        if ($topLevelVolumes->has($source)) {
                                            $v = $topLevelVolumes->get($source);
                                            if (data_get($v, 'driver_opts.type') === 'cifs') {
                                                // Do nothing
                                            } else {
                                                if (is_null(data_get($v, 'name'))) {
                                                    data_set($v, 'name', $source);
                                                    data_set($topLevelVolumes, $source, $v);
                                                }
                                            }
                                        } else {
                                            $topLevelVolumes->put($source, [
                                                'name' => $source,
                                            ]);
                                        }
                                    }
                                }
                            }
                        }
                        if (is_array($volume)) {
                            return data_get($volume, 'source');
                        }
                        dispatch(new ServerFilesFromServerJob($resource));

                        return $volume->value();
                    });
                    data_set($service, 'volumes', $serviceVolumes->toArray());
                }
            }

            if ($pull_request_id !== 0 && count($serviceDependencies) > 0) {
                $serviceDependencies = $serviceDependencies->map(function ($dependency) use ($pull_request_id) {
                    return $dependency."-pr-$pull_request_id";
                });
                data_set($service, 'depends_on', $serviceDependencies->toArray());
            }

            // Decide if the service is a database
            $isDatabase = isDatabaseImage(data_get_str($service, 'image'));
            data_set($service, 'is_database', $isDatabase);

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
                        if (is_string($networkDetails) || is_int($networkDetails)) {
                            $topLevelNetworks->put($networkDetails, null);
                        }
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
            if ($collectedPorts->count() > 0) {
                ray($collectedPorts->implode(','));
            }
            $definedNetworkExists = $topLevelNetworks->contains(function ($value, $_) use ($definedNetwork) {
                return $value == $definedNetwork;
            });
            if (! $definedNetworkExists) {
                foreach ($definedNetwork as $network) {
                    if ($pull_request_id !== 0) {
                        $topLevelNetworks->put($network, [
                            'name' => $network,
                            'external' => true,
                        ]);
                    } else {
                        $topLevelNetworks->put($network, [
                            'name' => $network,
                            'external' => true,
                        ]);
                    }
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
            if (data_get($resource, 'settings.connect_to_docker_network')) {
                $network = $resource->destination->network;
                $networks->put($network, null);
                $topLevelNetworks->put($network, [
                    'name' => $network,
                    'external' => true,
                ]);
            }
            data_set($service, 'networks', $networks->toArray());
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
                    if ($isNew) {
                        $name = $key->after('SERVICE_FQDN_')->beforeLast('_')->lower();
                        $fqdn = generateFqdn($server, "{$name->value()}-{$resource->uuid}");
                        if (substr_count($key->value(), '_') === 3) {
                            // SERVICE_FQDN_UMAMI_1000
                            $port = $key->afterLast('_');
                        } else {
                            // SERVICE_FQDN_UMAMI
                            $port = null;
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
                    }

                    continue;
                }
                if ($value?->startsWith('$')) {
                    $foundEnv = EnvironmentVariable::where([
                        'key' => $key,
                        'application_id' => $resource->id,
                        'is_preview' => false,
                    ])->first();
                    $value = replaceVariables($value);
                    $key = $value;
                    if ($value->startsWith('SERVICE_')) {
                        $foundEnv = EnvironmentVariable::where([
                            'key' => $key,
                            'application_id' => $resource->id,
                        ])->first();
                        ['command' => $command, 'forService' => $forService, 'generatedValue' => $generatedValue, 'port' => $port] = parseEnvVariable($value);
                        if (! is_null($command)) {
                            if ($command?->value() === 'FQDN' || $command?->value() === 'URL') {
                                if (Str::lower($forService) === $serviceName) {
                                    $fqdn = generateFqdn($server, $containerName);
                                } else {
                                    $fqdn = generateFqdn($server, Str::lower($forService).'-'.$resource->uuid);
                                }
                                if ($port) {
                                    $fqdn = "$fqdn:$port";
                                }
                                if ($foundEnv) {
                                    $fqdn = data_get($foundEnv, 'value');
                                } else {
                                    if ($command?->value() === 'URL') {
                                        $fqdn = str($fqdn)->after('://')->value();
                                    }
                                    EnvironmentVariable::create([
                                        'key' => $key,
                                        'value' => $fqdn,
                                        'is_build_time' => false,
                                        'application_id' => $resource->id,
                                        'is_preview' => false,
                                    ]);
                                }
                            } else {
                                $generatedValue = generateEnvValue($command);
                                if (! $foundEnv) {
                                    EnvironmentVariable::create([
                                        'key' => $key,
                                        'value' => $generatedValue,
                                        'is_build_time' => false,
                                        'application_id' => $resource->id,
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
                            'application_id' => $resource->id,
                            'is_preview' => false,
                        ])->first();
                        if ($foundEnv) {
                            $defaultValue = data_get($foundEnv, 'value');
                        }
                        $isBuildTime = data_get($foundEnv, 'is_build_time', false);
                        if ($foundEnv) {
                            $foundEnv->update([
                                'key' => $key,
                                'application_id' => $resource->id,
                                'is_build_time' => $isBuildTime,
                                'value' => $defaultValue,
                            ]);
                        } else {
                            EnvironmentVariable::create([
                                'key' => $key,
                                'value' => $defaultValue,
                                'is_build_time' => $isBuildTime,
                                'application_id' => $resource->id,
                                'is_preview' => false,
                            ]);
                        }
                    }
                }
            }
            // Add labels to the service
            if ($resource->serviceType()) {
                $fqdns = generateServiceSpecificFqdns($resource);
            } else {
                $domains = collect(json_decode($resource->docker_compose_domains)) ?? [];
                if ($domains) {
                    $fqdns = data_get($domains, "$serviceName.domain");
                    if ($fqdns) {
                        $fqdns = str($fqdns)->explode(',');
                        if ($pull_request_id !== 0) {
                            $preview = $resource->previews()->find($preview_id);
                            $docker_compose_domains = collect(json_decode(data_get($preview, 'docker_compose_domains')));
                            if ($docker_compose_domains->count() > 0) {
                                $found_fqdn = data_get($docker_compose_domains, "$serviceName.domain");
                                if ($found_fqdn) {
                                    $fqdns = collect($found_fqdn);
                                } else {
                                    $fqdns = collect([]);
                                }
                            } else {
                                $fqdns = $fqdns->map(function ($fqdn) use ($pull_request_id, $resource) {
                                    $preview = ApplicationPreview::findPreviewByApplicationAndPullId($resource->id, $pull_request_id);
                                    $url = Url::fromString($fqdn);
                                    $template = $resource->preview_url_template;
                                    $host = $url->getHost();
                                    $schema = $url->getScheme();
                                    $random = new Cuid2;
                                    $preview_fqdn = str_replace('{{random}}', $random, $template);
                                    $preview_fqdn = str_replace('{{domain}}', $host, $preview_fqdn);
                                    $preview_fqdn = str_replace('{{pr_id}}', $pull_request_id, $preview_fqdn);
                                    $preview_fqdn = "$schema://$preview_fqdn";
                                    $preview->fqdn = $preview_fqdn;
                                    $preview->save();

                                    return $preview_fqdn;
                                });
                            }
                        }
                        $shouldGenerateLabelsExactly = $server->settings->generate_exact_labels;
                        if ($shouldGenerateLabelsExactly) {
                            switch ($server->proxyType()) {
                                case ProxyTypes::TRAEFIK->value:
                                    $serviceLabels = $serviceLabels->merge(
                                        fqdnLabelsForTraefik(
                                            uuid: $resource->uuid,
                                            domains: $fqdns,
                                            serviceLabels: $serviceLabels,
                                            generate_unique_uuid: $resource->build_pack === 'dockercompose',
                                            image: data_get($service, 'image'),
                                            is_force_https_enabled: $resource->isForceHttpsEnabled(),
                                            is_gzip_enabled: $resource->isGzipEnabled(),
                                            is_stripprefix_enabled: $resource->isStripprefixEnabled(),
                                        )
                                    );
                                    break;
                                case ProxyTypes::CADDY->value:
                                    $serviceLabels = $serviceLabels->merge(
                                        fqdnLabelsForCaddy(
                                            network: $resource->destination->network,
                                            uuid: $resource->uuid,
                                            domains: $fqdns,
                                            serviceLabels: $serviceLabels,
                                            image: data_get($service, 'image'),
                                            is_force_https_enabled: $resource->isForceHttpsEnabled(),
                                            is_gzip_enabled: $resource->isGzipEnabled(),
                                            is_stripprefix_enabled: $resource->isStripprefixEnabled(),
                                        )
                                    );
                                    break;
                            }
                        } else {
                            $serviceLabels = $serviceLabels->merge(
                                fqdnLabelsForTraefik(
                                    uuid: $resource->uuid,
                                    domains: $fqdns,
                                    serviceLabels: $serviceLabels,
                                    generate_unique_uuid: $resource->build_pack === 'dockercompose',
                                    image: data_get($service, 'image'),
                                    is_force_https_enabled: $resource->isForceHttpsEnabled(),
                                    is_gzip_enabled: $resource->isGzipEnabled(),
                                    is_stripprefix_enabled: $resource->isStripprefixEnabled(),
                                )
                            );
                            $serviceLabels = $serviceLabels->merge(
                                fqdnLabelsForCaddy(
                                    network: $resource->destination->network,
                                    uuid: $resource->uuid,
                                    domains: $fqdns,
                                    serviceLabels: $serviceLabels,
                                    image: data_get($service, 'image'),
                                    is_force_https_enabled: $resource->isForceHttpsEnabled(),
                                    is_gzip_enabled: $resource->isGzipEnabled(),
                                    is_stripprefix_enabled: $resource->isStripprefixEnabled(),
                                )
                            );
                        }
                    }
                }
            }
            $defaultLabels = defaultLabels($resource->id, $containerName, $pull_request_id, type: 'application');
            $serviceLabels = $serviceLabels->merge($defaultLabels);

            if ($server->isLogDrainEnabled()) {
                if ($resource instanceof Application && $resource->isLogDrainEnabled()) {
                    data_set($service, 'logging', generate_fluentd_configuration());
                }
            }
            if ($serviceLabels->count() > 0) {
                if ($resource->settings->is_container_label_escape_enabled) {
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
            data_set($service, 'container_name', $containerName);
            data_forget($service, 'volumes.*.content');
            data_forget($service, 'volumes.*.isDirectory');

            return $service;
        });
        if ($pull_request_id !== 0) {
            $services->each(function ($service, $serviceName) use ($pull_request_id, $services) {
                $services[$serviceName."-pr-$pull_request_id"] = $service;
                data_forget($services, $serviceName);
            });
        }
        $finalServices = [
            'services' => $services->toArray(),
            'volumes' => $topLevelVolumes->toArray(),
            'networks' => $topLevelNetworks->toArray(),
            'configs' => $topLevelConfigs->toArray(),
            'secrets' => $topLevelSecrets->toArray(),
        ];
        $resource->docker_compose_raw = Yaml::dump($yaml, 10, 2);
        $resource->docker_compose = Yaml::dump($finalServices, 10, 2);
        data_forget($resource, 'environment_variables');
        data_forget($resource, 'environment_variables_preview');
        $resource->save();

        return collect($finalServices);
    }
}

function newParser(Application|Service $resource, int $pull_request_id = 0, ?int $preview_id = null): Collection
{
    $isApplication = $resource instanceof Application;
    $isService = $resource instanceof Service;

    $uuid = data_get($resource, 'uuid');
    $compose = data_get($resource, 'docker_compose_raw');
    if (! $compose) {
        return collect([]);
    }

    if ($isApplication) {
        $nameOfId = 'application_id';
        $pullRequestId = $pull_request_id;
        $isPullRequest = $pullRequestId == 0 ? false : true;
        $server = data_get($resource, 'destination.server');
        $fileStorages = $resource->fileStorages();
    } elseif ($isService) {
        $nameOfId = 'service_id';
        $server = data_get($resource, 'server');
        $allServices = get_service_templates();
    } else {
        return collect([]);
    }

    try {
        $yaml = Yaml::parse($compose);
    } catch (\Exception $e) {
        return collect([]);
    }

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
    if ($isApplication && $isPullRequest) {
        $baseNetwork = collect(["{$uuid}-{$pullRequestId}"]);
    }

    $parsedServices = collect([]);
    // ray()->clearAll();

    $allMagicEnvironments = collect([]);
    foreach ($services as $serviceName => $service) {
        $predefinedPort = null;
        $magicEnvironments = collect([]);
        $image = data_get_str($service, 'image');
        $environment = collect(data_get($service, 'environment', []));
        $buildArgs = collect(data_get($service, 'build.args', []));
        $environment = $environment->merge($buildArgs);
        $isDatabase = isDatabaseImage(data_get_str($service, 'image'));

        if ($isService) {
            $containerName = "$serviceName-{$resource->uuid}";

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
            if ($isDatabase) {
                $applicationFound = ServiceApplication::where('name', $serviceName)->where('image', $image)->where('service_id', $resource->id)->first();
                if ($applicationFound) {
                    $savedService = $applicationFound;
                    $savedService = ServiceDatabase::firstOrCreate([
                        'name' => $applicationFound->name,
                        'image' => $applicationFound->image,
                        'service_id' => $applicationFound->service_id,
                    ]);
                    $applicationFound->delete();
                } else {
                    $savedService = ServiceDatabase::firstOrCreate([
                        'name' => $serviceName,
                        'image' => $image,
                        'service_id' => $resource->id,
                    ]);
                }
            } else {
                $savedService = ServiceApplication::firstOrCreate([
                    'name' => $serviceName,
                    'image' => $image,
                    'service_id' => $resource->id,
                ]);
            }
            $environment = collect(data_get($service, 'environment', []));
            $buildArgs = collect(data_get($service, 'build.args', []));
            $environment = $environment->merge($buildArgs);

            // convert environment variables to one format
            $environment = convertComposeEnvironmentToArray($environment);

            // Add Coolify defined environments
            $allEnvironments = $resource->environment_variables()->get(['key', 'value']);

            $allEnvironments = $allEnvironments->mapWithKeys(function ($item) {
                return [$item['key'] => $item['value']];
            });
            // filter and add magic environments
            foreach ($environment as $key => $value) {
                // Get all SERVICE_ variables from keys and values
                $key = str($key);
                $value = str($value);

                $regex = '/\$(\{?([a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*)\}?)/';
                preg_match_all($regex, $value, $valueMatches);
                if (count($valueMatches[1]) > 0) {
                    foreach ($valueMatches[1] as $match) {
                        $match = replaceVariables($match);
                        if ($match->startsWith('SERVICE_')) {
                            if ($magicEnvironments->has($match->value())) {
                                continue;
                            }
                            $magicEnvironments->put($match->value(), '');
                        }
                    }
                }

                // Get magic environments where we need to preset the FQDN
                if ($key->startsWith('SERVICE_FQDN_')) {
                    // SERVICE_FQDN_APP or SERVICE_FQDN_APP_3000
                    if (substr_count(str($key)->value(), '_') === 3) {
                        $fqdnFor = $key->after('SERVICE_FQDN_')->beforeLast('_')->lower()->value();
                        $port = $key->afterLast('_')->value();
                    } else {
                        $fqdnFor = $key->after('SERVICE_FQDN_')->lower()->value();
                        $port = null;
                    }
                    if ($isApplication) {
                        $fqdn = generateFqdn($server, "{$resource->name}-$uuid");
                    } elseif ($isService) {
                        if ($fqdnFor) {
                            $fqdn = generateFqdn($server, "$fqdnFor-$uuid");
                        } else {
                            $fqdn = generateFqdn($server, "{$savedService->name}-$uuid");
                        }
                    }

                    if ($value && get_class($value) === 'Illuminate\Support\Stringable' && $value->startsWith('/')) {
                        $path = $value->value();
                        if ($path !== '/') {
                            $fqdn = "$fqdn$path";
                        }
                    }
                    $fqdnWithPort = $fqdn;
                    if ($port) {
                        $fqdnWithPort = "$fqdn:$port";
                    }
                    if ($isApplication && is_null($resource->fqdn)) {
                        data_forget($resource, 'environment_variables');
                        data_forget($resource, 'environment_variables_preview');
                        $resource->fqdn = $fqdnWithPort;
                        $resource->save();
                    } elseif ($isService && is_null($savedService->fqdn)) {
                        $savedService->fqdn = $fqdnWithPort;
                        $savedService->save();
                    }

                    if (substr_count(str($key)->value(), '_') === 2) {
                        $resource->environment_variables()->where('key', $key->value())->where($nameOfId, $resource->id)->firstOrCreate([
                            'key' => $key->value(),
                            $nameOfId => $resource->id,
                        ], [
                            'value' => $fqdn,
                            'is_build_time' => false,
                            'is_preview' => false,
                        ]);
                    }
                    if (substr_count(str($key)->value(), '_') === 3) {
                        $newKey = str($key)->beforeLast('_');
                        $resource->environment_variables()->where('key', $newKey->value())->where($nameOfId, $resource->id)->firstOrCreate([
                            'key' => $newKey->value(),
                            $nameOfId => $resource->id,
                        ], [
                            'value' => $fqdn,
                            'is_build_time' => false,
                            'is_preview' => false,
                        ]);
                    }
                }
            }

            $allMagicEnvironments = $allMagicEnvironments->merge($magicEnvironments);
            if ($magicEnvironments->count() > 0) {
                foreach ($magicEnvironments as $key => $value) {
                    $key = str($key);
                    $value = replaceVariables($value);
                    $command = parseCommandFromMagicEnvVariable($key);
                    $found = $resource->environment_variables()->where('key', $key->value())->where($nameOfId, $resource->id)->first();
                    if ($found) {
                        continue;
                    }
                    if ($command->value() === 'FQDN') {
                        $fqdnFor = $key->after('SERVICE_FQDN_')->lower()->value();
                        if (str($fqdnFor)->contains('_')) {
                            $fqdnFor = str($fqdnFor)->before('_');
                        }
                        if ($isApplication) {
                            $fqdn = generateFqdn($server, "{$resource->name}-$uuid");
                        } elseif ($isService) {
                            $fqdn = generateFqdn($server, "$fqdnFor-$uuid");
                        }
                        $resource->environment_variables()->where('key', $key->value())->where($nameOfId, $resource->id)->firstOrCreate([
                            'key' => $key->value(),
                            $nameOfId => $resource->id,
                        ], [
                            'value' => $fqdn,
                            'is_build_time' => false,
                            'is_preview' => false,
                        ]);
                    } elseif ($command->value() === 'URL') {
                        $fqdnFor = $key->after('SERVICE_URL_')->lower()->value();
                        if (str($fqdnFor)->contains('_')) {
                            $fqdnFor = str($fqdnFor)->before('_');
                        }
                        if ($isApplication) {
                            $fqdn = generateFqdn($server, "{$resource->name}-$uuid");
                        } elseif ($isService) {
                            $fqdn = generateFqdn($server, "$fqdnFor-$uuid");
                        }
                        $resource->environment_variables()->where('key', $key->value())->where($nameOfId, $resource->id)->firstOrCreate([
                            'key' => $key->value(),
                            $nameOfId => $resource->id,
                        ], [
                            'value' => $fqdn,
                            'is_build_time' => false,
                            'is_preview' => false,
                        ]);

                    } else {
                        $value = generateEnvValue($command, $resource);
                        $resource->environment_variables()->where('key', $key->value())->where($nameOfId, $resource->id)->firstOrCreate([
                            'key' => $key->value(),
                            $nameOfId => $resource->id,
                        ], [
                            'value' => $value,
                            'is_build_time' => false,
                            'is_preview' => false,
                        ]);
                    }
                }
            }
        }
    }

    // Parse the rest of the services
    foreach ($services as $serviceName => $service) {
        $image = data_get_str($service, 'image');
        $restart = data_get_str($service, 'restart', RESTART_MODE);
        $logging = data_get($service, 'logging');

        if ($server->isLogDrainEnabled()) {
            if ($resource instanceof Application && $resource->isLogDrainEnabled()) {
                $logging = generate_fluentd_configuration();
            }
        }
        $volumes = collect(data_get($service, 'volumes', []));
        $networks = collect(data_get($service, 'networks', []));
        $use_network_mode = data_get($service, 'network_mode') !== null;
        $depends_on = collect(data_get($service, 'depends_on', []));
        $labels = collect(data_get($service, 'labels', []));
        $environment = collect(data_get($service, 'environment', []));
        $ports = collect(data_get($service, 'ports', []));
        $buildArgs = collect(data_get($service, 'build.args', []));
        $environment = $environment->merge($buildArgs);

        $environment = convertComposeEnvironmentToArray($environment);
        $coolifyEnvironments = collect([]);

        $isDatabase = isDatabaseImage(data_get_str($service, 'image'));
        $volumesParsed = collect([]);

        if ($isApplication) {
            $baseName = generateApplicationContainerName(
                application: $resource,
                pull_request_id: $pullRequestId
            );
            $containerName = "$serviceName-$baseName";
            $predefinedPort = null;
        } elseif ($isService) {
            $containerName = "$serviceName-{$resource->uuid}";

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

            if ($isDatabase) {
                $applicationFound = ServiceApplication::where('name', $serviceName)->where('image', $image)->where('service_id', $resource->id)->first();
                if ($applicationFound) {
                    $savedService = $applicationFound;
                    $savedService = ServiceDatabase::firstOrCreate([
                        'name' => $applicationFound->name,
                        'image' => $applicationFound->image,
                        'service_id' => $applicationFound->service_id,
                    ]);
                    $applicationFound->delete();
                } else {
                    $savedService = ServiceDatabase::firstOrCreate([
                        'name' => $serviceName,
                        'image' => $image,
                        'service_id' => $resource->id,
                    ]);
                }
            } else {
                $savedService = ServiceApplication::firstOrCreate([
                    'name' => $serviceName,
                    'image' => $image,
                    'service_id' => $resource->id,
                ]);
            }
            $fileStorages = $savedService->fileStorages();
            if ($savedService->image !== $image) {
                $savedService->image = $image;
                $savedService->save();
            }
        }

        $originalResource = $isApplication ? $resource : $savedService;

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
                        $volume = $source->value().':'.$target->value();
                    } elseif ($source->value() === '/tmp' || $source->value() === '/tmp/') {
                        $volume = $source->value().':'.$target->value();
                    } else {
                        if ((int) $resource->compose_parsing_version >= 4) {
                            if ($isApplication) {
                                $mainDirectory = str(base_configuration_dir().'/applications/'.$uuid);
                            } elseif ($isService) {
                                $mainDirectory = str(base_configuration_dir().'/services/'.$uuid);
                            }
                        } else {
                            $mainDirectory = str(base_configuration_dir().'/applications/'.$uuid);
                        }
                        $source = replaceLocalSource($source, $mainDirectory);
                        if ($isApplication && $isPullRequest) {
                            $source = $source."-pr-$pullRequestId";
                        }
                        LocalFileVolume::updateOrCreate(
                            [
                                'mount_path' => $target,
                                'resource_id' => $originalResource->id,
                                'resource_type' => get_class($originalResource),
                            ],
                            [
                                'fs_path' => $source,
                                'mount_path' => $target,
                                'content' => $content,
                                'is_directory' => $isDirectory,
                                'resource_id' => $originalResource->id,
                                'resource_type' => get_class($originalResource),
                            ]
                        );
                        if (isDev()) {
                            if ((int) $resource->compose_parsing_version >= 4) {
                                if ($isApplication) {
                                    $source = $source->replace($mainDirectory, '/var/lib/docker/volumes/coolify_dev_coolify_data/_data/applications/'.$uuid);
                                } elseif ($isService) {
                                    $source = $source->replace($mainDirectory, '/var/lib/docker/volumes/coolify_dev_coolify_data/_data/services/'.$uuid);
                                }
                            } else {
                                $source = $source->replace($mainDirectory, '/var/lib/docker/volumes/coolify_dev_coolify_data/_data/applications/'.$uuid);
                            }
                        }
                        $volume = "$source:$target";
                    }
                } elseif ($type->value() === 'volume') {
                    if ($topLevel->get('volumes')->has($source->value())) {
                        $temp = $topLevel->get('volumes')->get($source->value());
                        if (data_get($temp, 'driver_opts.type') === 'cifs') {
                            continue;
                        }
                        if (data_get($temp, 'driver_opts.type') === 'nfs') {
                            continue;
                        }
                    }
                    $slugWithoutUuid = Str::slug($source, '-');
                    $name = "{$uuid}_{$slugWithoutUuid}";

                    if ($isApplication && $isPullRequest) {
                        $name = "{$name}-pr-$pullRequestId";
                    }
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
                            'name' => $name,
                            'resource_id' => $originalResource->id,
                            'resource_type' => get_class($originalResource),
                        ],
                        [
                            'name' => $name,
                            'mount_path' => $target,
                            'resource_id' => $originalResource->id,
                            'resource_type' => get_class($originalResource),
                        ]
                    );
                }
                dispatch(new ServerFilesFromServerJob($originalResource));
                $volumesParsed->put($index, $volume);
            }
        }

        if ($depends_on?->count() > 0) {
            if ($isApplication && $isPullRequest) {
                $newDependsOn = collect([]);
                $depends_on->each(function ($dependency, $condition) use ($pullRequestId, $newDependsOn) {
                    if (is_numeric($condition)) {
                        $dependency = "$dependency-pr-$pullRequestId";

                        $newDependsOn->put($condition, $dependency);
                    } else {
                        $condition = "$condition-pr-$pullRequestId";
                        $newDependsOn->put($condition, $dependency);
                    }
                });
                $depends_on = $newDependsOn;
            }
        }
        if (! $use_network_mode) {
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
        }

        // Collect/create/update ports
        $collectedPorts = collect([]);
        if ($ports->count() > 0) {
            foreach ($ports as $sport) {
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
        if ($isService) {
            $originalResource->ports = $collectedPorts->implode(',');
            $originalResource->save();
        }

        $networks_temp = collect();

        if (! $use_network_mode) {
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

            if ($isApplication) {
                if (data_get($resource, 'settings.connect_to_docker_network')) {
                    $network = $resource->destination->network;
                    $networks_temp->put($network, null);
                    $topLevel->get('networks')->put($network, [
                        'name' => $network,
                        'external' => true,
                    ]);
                }
            }
        }

        $normalEnvironments = $environment->diffKeys($allMagicEnvironments);
        $normalEnvironments = $normalEnvironments->filter(function ($value, $key) {
            return ! str($value)->startsWith('SERVICE_');
        });

        foreach ($normalEnvironments as $key => $value) {
            $key = str($key);
            $value = str($value);
            $originalValue = $value;
            $parsedValue = replaceVariables($value);
            if ($value->startsWith('$SERVICE_')) {
                $resource->environment_variables()->where('key', $key)->where($nameOfId, $resource->id)->firstOrCreate([
                    'key' => $key,
                    $nameOfId => $resource->id,
                ], [
                    'value' => $value,
                    'is_build_time' => false,
                    'is_preview' => false,
                ]);

                continue;
            }
            if (! $value->startsWith('$')) {
                continue;
            }
            if ($key->value() === $parsedValue->value()) {
                $value = null;
                $resource->environment_variables()->where('key', $key)->where($nameOfId, $resource->id)->firstOrCreate([
                    'key' => $key,
                    $nameOfId => $resource->id,
                ], [
                    'value' => $value,
                    'is_build_time' => false,
                    'is_preview' => false,
                ]);
            } else {
                if ($value->startsWith('$')) {
                    if ($value->contains(':-')) {
                        $value = replaceVariables($value);
                        $key = $value->before(':');
                        $value = $value->after(':-');
                    } elseif ($value->contains('-')) {
                        $value = replaceVariables($value);

                        $key = $value->before('-');
                        $value = $value->after('-');
                    } elseif ($value->contains(':?')) {
                        $value = replaceVariables($value);

                        $key = $value->before(':');
                        $value = $value->after(':?');
                    } elseif ($value->contains('?')) {
                        $value = replaceVariables($value);

                        $key = $value->before('?');
                        $value = $value->after('?');
                    }
                    if ($originalValue->value() === $value->value()) {
                        // This means the variable does not have a default value, so it needs to be created in Coolify
                        $parsedKeyValue = replaceVariables($value);
                        $resource->environment_variables()->where('key', $parsedKeyValue)->where($nameOfId, $resource->id)->firstOrCreate([
                            'key' => $parsedKeyValue,
                            $nameOfId => $resource->id,
                        ], [
                            'is_build_time' => false,
                            'is_preview' => false,
                        ]);
                        // Add the variable to the environment so it will be shown in the deployable compose file
                        $environment[$parsedKeyValue->value()] = $resource->environment_variables()->where('key', $parsedKeyValue)->where($nameOfId, $resource->id)->first()->value;

                        continue;
                    }
                    $resource->environment_variables()->where('key', $key)->where($nameOfId, $resource->id)->firstOrCreate([
                        'key' => $key,
                        $nameOfId => $resource->id,
                    ], [
                        'value' => $value,
                        'is_build_time' => false,
                        'is_preview' => false,
                    ]);
                }

            }
        }
        if ($isApplication) {
            $branch = $originalResource->git_branch;
            if ($pullRequestId !== 0) {
                $branch = "pull/{$pullRequestId}/head";
            }
            if ($originalResource->environment_variables->where('key', 'COOLIFY_BRANCH')->isEmpty()) {
                $coolifyEnvironments->put('COOLIFY_BRANCH', "\"{$branch}\"");
            }
        }

        // Add COOLIFY_CONTAINER_NAME to environment
        if ($resource->environment_variables->where('key', 'COOLIFY_CONTAINER_NAME')->isEmpty()) {
            $coolifyEnvironments->put('COOLIFY_CONTAINER_NAME', "\"{$containerName}\"");
        }

        if ($isApplication) {
            $domains = collect(json_decode($resource->docker_compose_domains)) ?? collect([]);
            $fqdns = data_get($domains, "$serviceName.domain");
            if ($fqdns) {
                $fqdns = str($fqdns)->explode(',');
                if ($isPullRequest) {
                    $preview = $resource->previews()->find($preview_id);
                    $docker_compose_domains = collect(json_decode(data_get($preview, 'docker_compose_domains')));
                    if ($docker_compose_domains->count() > 0) {
                        $found_fqdn = data_get($docker_compose_domains, "$serviceName.domain");
                        if ($found_fqdn) {
                            $fqdns = collect($found_fqdn);
                        } else {
                            $fqdns = collect([]);
                        }
                    } else {
                        $fqdns = $fqdns->map(function ($fqdn) use ($pullRequestId, $resource) {
                            $preview = ApplicationPreview::findPreviewByApplicationAndPullId($resource->id, $pullRequestId);
                            $url = Url::fromString($fqdn);
                            $template = $resource->preview_url_template;
                            $host = $url->getHost();
                            $schema = $url->getScheme();
                            $random = new Cuid2;
                            $preview_fqdn = str_replace('{{random}}', $random, $template);
                            $preview_fqdn = str_replace('{{domain}}', $host, $preview_fqdn);
                            $preview_fqdn = str_replace('{{pr_id}}', $pullRequestId, $preview_fqdn);
                            $preview_fqdn = "$schema://$preview_fqdn";
                            $preview->fqdn = $preview_fqdn;
                            $preview->save();

                            return $preview_fqdn;
                        });
                    }
                }
            }
            $defaultLabels = defaultLabels(
                id: $resource->id,
                name: $containerName,
                pull_request_id: $pullRequestId,
                type: 'application'
            );
        } elseif ($isService) {
            if ($savedService->serviceType()) {
                $fqdns = generateServiceSpecificFqdns($savedService);
            } else {
                $fqdns = collect(data_get($savedService, 'fqdns'))->filter();
            }
            $defaultLabels = defaultLabels($resource->id, $containerName, type: 'service', subType: $isDatabase ? 'database' : 'application', subId: $savedService->id);
        }
        // Add COOLIFY_FQDN & COOLIFY_URL to environment
        if (! $isDatabase && $fqdns instanceof Collection && $fqdns->count() > 0) {
            $coolifyEnvironments->put('COOLIFY_URL', $fqdns->implode(','));

            $urls = $fqdns->map(function ($fqdn) {
                return str($fqdn)->replace('http://', '')->replace('https://', '');
            });
            $coolifyEnvironments->put('COOLIFY_FQDN', $urls->implode(','));
        }
        add_coolify_default_environment_variables($resource, $coolifyEnvironments, $resource->environment_variables);

        if ($environment->count() > 0) {
            $environment = $environment->filter(function ($value, $key) {
                return ! str($key)->startsWith('SERVICE_FQDN_');
            })->map(function ($value, $key) use ($resource) {
                // if value is empty, set it to null so if you set the environment variable in the .env file (Coolify's UI), it will used
                if (str($value)->isEmpty()) {
                    if ($resource->environment_variables()->where('key', $key)->exists()) {
                        $value = $resource->environment_variables()->where('key', $key)->first()->value;
                    } else {
                        $value = null;
                    }
                }

                return $value;
            });
        }
        $serviceLabels = $labels->merge($defaultLabels);
        if ($serviceLabels->count() > 0) {
            if ($isApplication) {
                $isContainerLabelEscapeEnabled = data_get($resource, 'settings.is_container_label_escape_enabled');
            } else {
                $isContainerLabelEscapeEnabled = data_get($resource, 'is_container_label_escape_enabled');
            }
            if ($isContainerLabelEscapeEnabled) {
                $serviceLabels = $serviceLabels->map(function ($value, $key) {
                    return escapeDollarSign($value);
                });
            }
        }
        if (! $isDatabase && $fqdns instanceof Collection && $fqdns->count() > 0) {
            if ($isApplication) {
                $shouldGenerateLabelsExactly = $resource->destination->server->settings->generate_exact_labels;
                $uuid = $resource->uuid;
                $network = data_get($resource, 'destination.network');
                if ($isPullRequest) {
                    $uuid = "{$resource->uuid}-{$pullRequestId}";
                }
                if ($isPullRequest) {
                    $network = "{$resource->destination->network}-{$pullRequestId}";
                }
            } else {
                $shouldGenerateLabelsExactly = $resource->server->settings->generate_exact_labels;
                $uuid = $resource->uuid;
                $network = data_get($resource, 'destination.network');
            }
            if ($shouldGenerateLabelsExactly) {
                switch ($server->proxyType()) {
                    case ProxyTypes::TRAEFIK->value:
                        $serviceLabels = $serviceLabels->merge(fqdnLabelsForTraefik(
                            uuid: $uuid,
                            domains: $fqdns,
                            is_force_https_enabled: true,
                            serviceLabels: $serviceLabels,
                            is_gzip_enabled: $originalResource->isGzipEnabled(),
                            is_stripprefix_enabled: $originalResource->isStripprefixEnabled(),
                            service_name: $serviceName,
                            image: $image
                        ));
                        break;
                    case ProxyTypes::CADDY->value:
                        $serviceLabels = $serviceLabels->merge(fqdnLabelsForCaddy(
                            network: $network,
                            uuid: $uuid,
                            domains: $fqdns,
                            is_force_https_enabled: true,
                            serviceLabels: $serviceLabels,
                            is_gzip_enabled: $originalResource->isGzipEnabled(),
                            is_stripprefix_enabled: $originalResource->isStripprefixEnabled(),
                            service_name: $serviceName,
                            image: $image,
                            predefinedPort: $predefinedPort
                        ));
                        break;
                }
            } else {
                $serviceLabels = $serviceLabels->merge(fqdnLabelsForTraefik(
                    uuid: $uuid,
                    domains: $fqdns,
                    is_force_https_enabled: true,
                    serviceLabels: $serviceLabels,
                    is_gzip_enabled: $originalResource->isGzipEnabled(),
                    is_stripprefix_enabled: $originalResource->isStripprefixEnabled(),
                    service_name: $serviceName,
                    image: $image
                ));
                $serviceLabels = $serviceLabels->merge(fqdnLabelsForCaddy(
                    network: $network,
                    uuid: $uuid,
                    domains: $fqdns,
                    is_force_https_enabled: true,
                    serviceLabels: $serviceLabels,
                    is_gzip_enabled: $originalResource->isGzipEnabled(),
                    is_stripprefix_enabled: $originalResource->isStripprefixEnabled(),
                    service_name: $serviceName,
                    image: $image,
                    predefinedPort: $predefinedPort

                ));
            }
        }
        if ($isService) {
            if (data_get($service, 'restart') === 'no' || data_get($service, 'exclude_from_hc')) {
                $savedService->update(['exclude_from_status' => true]);
            }
        }
        data_forget($service, 'volumes.*.content');
        data_forget($service, 'volumes.*.isDirectory');
        data_forget($service, 'volumes.*.is_directory');
        data_forget($service, 'exclude_from_hc');

        $volumesParsed = $volumesParsed->map(function ($volume) {
            data_forget($volume, 'content');
            data_forget($volume, 'is_directory');
            data_forget($volume, 'isDirectory');

            return $volume;
        });

        $payload = collect($service)->merge([
            'container_name' => $containerName,
            'restart' => $restart->value(),
            'labels' => $serviceLabels,
        ]);
        if (! $use_network_mode) {
            $payload['networks'] = $networks_temp;
        }
        if ($ports->count() > 0) {
            $payload['ports'] = $ports;
        }
        if ($volumesParsed->count() > 0) {
            $payload['volumes'] = $volumesParsed;
        }
        if ($environment->count() > 0 || $coolifyEnvironments->count() > 0) {
            $payload['environment'] = $environment->merge($coolifyEnvironments);
        }
        if ($logging) {
            $payload['logging'] = $logging;
        }
        if ($depends_on->count() > 0) {
            $payload['depends_on'] = $depends_on;
        }
        if ($isApplication && $isPullRequest) {
            $serviceName = "{$serviceName}-pr-{$pullRequestId}";
        }

        $parsedServices->put($serviceName, $payload);
    }
    $topLevel->put('services', $parsedServices);

    $customOrder = ['services', 'volumes', 'networks', 'configs', 'secrets'];

    $topLevel = $topLevel->sortBy(function ($value, $key) use ($customOrder) {
        return array_search($key, $customOrder);
    });

    $resource->docker_compose = Yaml::dump(convertToArray($topLevel), 10, 2);
    data_forget($resource, 'environment_variables');
    data_forget($resource, 'environment_variables_preview');
    $resource->save();

    return $topLevel;
}

function generate_fluentd_configuration(): array
{
    return [
        'driver' => 'fluentd',
        'options' => [
            'fluentd-address' => 'tcp://127.0.0.1:24224',
            'fluentd-async' => 'true',
            'fluentd-sub-second-precision' => 'true',
            // env vars are used in the LogDrain configurations
            'env' => 'COOLIFY_APP_NAME,COOLIFY_PROJECT_NAME,COOLIFY_SERVER_IP,COOLIFY_ENVIRONMENT_NAME',
        ],
    ];
}

function isAssociativeArray($array)
{
    if ($array instanceof Collection) {
        $array = $array->toArray();
    }

    if (! is_array($array)) {
        throw new \InvalidArgumentException('Input must be an array or a Collection.');
    }

    if ($array === []) {
        return false;
    }

    return array_keys($array) !== range(0, count($array) - 1);
}

/**
 * This method adds the default environment variables to the resource.
 * - COOLIFY_APP_NAME
 * - COOLIFY_PROJECT_NAME
 * - COOLIFY_SERVER_IP
 * - COOLIFY_ENVIRONMENT_NAME
 *
 *  Theses variables are added in place to the $where_to_add array.
 */
function add_coolify_default_environment_variables(StandaloneRedis|StandalonePostgresql|StandaloneMongodb|StandaloneMysql|StandaloneMariadb|StandaloneKeydb|StandaloneDragonfly|StandaloneClickhouse|Application|Service $resource, Collection &$where_to_add, ?Collection $where_to_check = null)
{
    if ($resource instanceof Service) {
        $ip = $resource->server->ip;
    } else {
        $ip = $resource->destination->server->ip;
    }
    if (isAssociativeArray($where_to_add)) {
        $isAssociativeArray = true;
    } else {
        $isAssociativeArray = false;
    }
    if ($where_to_check != null && $where_to_check->where('key', 'COOLIFY_APP_NAME')->isEmpty()) {
        if ($isAssociativeArray) {
            $where_to_add->put('COOLIFY_APP_NAME', "\"{$resource->name}\"");
        } else {
            $where_to_add->push("COOLIFY_APP_NAME=\"{$resource->name}\"");
        }
    }
    if ($where_to_check != null && $where_to_check->where('key', 'COOLIFY_SERVER_IP')->isEmpty()) {
        if ($isAssociativeArray) {
            $where_to_add->put('COOLIFY_SERVER_IP', "\"{$ip}\"");
        } else {
            $where_to_add->push("COOLIFY_SERVER_IP=\"{$ip}\"");
        }
    }
    if ($where_to_check != null && $where_to_check->where('key', 'COOLIFY_ENVIRONMENT_NAME')->isEmpty()) {
        if ($isAssociativeArray) {
            $where_to_add->put('COOLIFY_ENVIRONMENT_NAME', "\"{$resource->environment->name}\"");
        } else {
            $where_to_add->push("COOLIFY_ENVIRONMENT_NAME=\"{$resource->environment->name}\"");
        }
    }
    if ($where_to_check != null && $where_to_check->where('key', 'COOLIFY_PROJECT_NAME')->isEmpty()) {
        if ($isAssociativeArray) {
            $where_to_add->put('COOLIFY_PROJECT_NAME', "\"{$resource->project()->name}\"");
        } else {
            $where_to_add->push("COOLIFY_PROJECT_NAME=\"{$resource->project()->name}\"");
        }
    }
}

function convertComposeEnvironmentToArray($environment)
{
    $convertedServiceVariables = collect([]);
    if (isAssociativeArray($environment)) {
        // Example: $environment = ['FOO' => 'bar', 'BAZ' => 'qux'];
        if ($environment instanceof Collection) {
            $changedEnvironment = collect([]);
            $environment->each(function ($value, $key) use ($changedEnvironment) {
                if (is_numeric($key)) {
                    $parts = explode('=', $value, 2);
                    if (count($parts) === 2) {
                        $key = $parts[0];
                        $realValue = $parts[1] ?? '';
                        $changedEnvironment->put($key, $realValue);
                    } else {
                        $changedEnvironment->put($key, $value);
                    }
                } else {
                    $changedEnvironment->put($key, $value);
                }
            });

            return $changedEnvironment;
        }
        $convertedServiceVariables = $environment;
    } else {
        // Example: $environment = ['FOO=bar', 'BAZ=qux'];
        foreach ($environment as $value) {
            if (is_string($value)) {
                $parts = explode('=', $value, 2);
                $key = $parts[0];
                $realValue = $parts[1] ?? '';
                if ($key) {
                    $convertedServiceVariables->put($key, $realValue);
                }
            }
        }
    }

    return $convertedServiceVariables;

}
function instanceSettings()
{
    return InstanceSettings::get();
}

function loadConfigFromGit(string $repository, string $branch, string $base_directory, int $server_id, int $team_id) {

    $server = Server::find($server_id)->where('team_id', $team_id)->first();
    if (!$server) {
        return;
    }
    $uuid = new Cuid2();
    $cloneCommand = "git clone --no-checkout -b $branch $repository .";
    $workdir = rtrim($base_directory, '/');
    $fileList = collect([".$workdir/coolify.json"]);
    $commands = collect([
        "rm -rf /tmp/{$uuid}",
        "mkdir -p /tmp/{$uuid}",
        "cd /tmp/{$uuid}",
        $cloneCommand,
        'git sparse-checkout init --cone',
        "git sparse-checkout set {$fileList->implode(' ')}",
        'git read-tree -mu HEAD',
        "cat .$workdir/coolify.json",
        'rm -rf /tmp/{$uuid}',
    ]);
    try {
        return instant_remote_process($commands, $server);
    } catch (\Exception $e) {
       // continue
    }
}
