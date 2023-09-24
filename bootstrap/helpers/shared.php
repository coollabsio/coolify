<?php

use App\Models\InstanceSettings;
use App\Models\Team;
use App\Models\User;
use App\Notifications\Channels\DiscordChannel;
use App\Notifications\Channels\EmailChannel;
use App\Notifications\Channels\TelegramChannel;
use App\Notifications\Internal\GeneralNotification;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Illuminate\Database\QueryException;
use Illuminate\Mail\Message;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Nubs\RandomNameGenerator\All;
use Poliander\Cron\CronExpression;
use Visus\Cuid2\Cuid2;
use phpseclib3\Crypt\RSA;
use Spatie\Url\Url;

function base_configuration_dir(): string
{
    return '/data/coolify';
}
function application_configuration_dir(): string
{
    return base_configuration_dir() . "/applications";
}
function service_configuration_dir(): string
{
    return base_configuration_dir() . "/services";
}
function database_configuration_dir(): string
{
    return base_configuration_dir() . "/databases";
}
function database_proxy_dir($uuid): string
{
    return base_configuration_dir() . "/databases/$uuid/proxy";
}
function backup_dir(): string
{
    return base_configuration_dir() . "/backups";
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
    return currentTeam()->show_boarding ?? false;
}
function refreshSession(?Team $team = null): void
{
    if (!$team) {
        if (auth()->user()?->currentTeam()) {
            $team = Team::find(auth()->user()->currentTeam()->id);
        } else {
            $team = User::find(auth()->user()->id)->teams->first();
        }
    }
    Cache::forget('team:' . auth()->user()->id);
    Cache::remember('team:' . auth()->user()->id, 3600, function () use ($team) {
        return $team;
    });
    session(['currentTeam' => $team]);
}
function handleError(?Throwable $error = null, ?Livewire\Component $livewire = null, ?string $customErrorMessage = null)
{
    ray('handleError');
    ray($error);
    if ($error instanceof Throwable) {
        $message = $error->getMessage();
    } else {
        $message = null;
    }
    if ($customErrorMessage) {
        $message = $customErrorMessage . ' ' . $message;
    }
    if ($error instanceof TooManyRequestsException) {
        if (isset($livewire)) {
            return $livewire->emit('error', "Too many requests. Please try again in {$error->secondsUntilAvailable} seconds.");
        }
        return "Too many requests. Please try again in {$error->secondsUntilAvailable} seconds.";
    }
    if (isset($livewire)) {
        $livewire->emit('error', $message);
        throw new RuntimeException($message);
    }

    throw new RuntimeException($message);
}
function general_error_handler(Throwable $err, Livewire\Component $that = null, $isJson = false, $customErrorMessage = null): mixed
{
    try {
        ray($err);
        ray('ERROR OCCURRED: ' . $err->getMessage());
        if ($err instanceof QueryException) {
            if ($err->errorInfo[0] === '23505') {
                throw new Exception($customErrorMessage ?? 'Duplicate entry found.', '23505');
            } else if (count($err->errorInfo) === 4) {
                throw new Exception($customErrorMessage ?? $err->errorInfo[3]);
            } else {
                throw new Exception($customErrorMessage ?? $err->errorInfo[2]);
            }
        } elseif ($err instanceof TooManyRequestsException) {
            throw new Exception($customErrorMessage ?? "Too many requests. Please try again in {$err->secondsUntilAvailable} seconds.");
        } else {
            if ($err->getMessage() === 'This action is unauthorized.') {
                return redirect()->route('dashboard')->with('error', $customErrorMessage ?? $err->getMessage());
            }
            throw new Exception($customErrorMessage ?? $err->getMessage());
        }
    } catch (\Throwable $e) {
        if ($that) {
            return $that->emit('error', $customErrorMessage ?? $e->getMessage());
        } elseif ($isJson) {
            return response()->json([
                'code' => $e->getCode(),
                'error' => $e->getMessage(),
            ]);
        } else {
            ray($customErrorMessage);
            ray($e);
            return $customErrorMessage ?? $e->getMessage();
        }
    }
}

function get_route_parameters(): array
{
    return Route::current()->parameters();
}

function get_latest_version_of_coolify(): string
{
    try {
        $response = Http::get('https://cdn.coollabs.io/coolify/versions.json');
        $versions = $response->json();
        return data_get($versions, 'coolify.v4.version');
    } catch (\Throwable $e) {
        //throw $e;
        ray($e->getMessage());
        return '0.0.0';
    }
}

function generate_random_name(?string $cuid = null): string
{
    $generator = All::create();
    if (is_null($cuid)) {
        $cuid = new Cuid2(7);
    }
    return Str::kebab("{$generator->getName()}-$cuid");
}
function generateSSHKey()
{
    $key = RSA::createKey();
    return [
        'private' => $key->toString('PKCS1'),
        'public' => $key->getPublicKey()->toString('OpenSSH', ['comment' => 'coolify-generated-ssh-key'])
    ];
}
function formatPrivateKey(string $privateKey)
{
    $privateKey = trim($privateKey);
    if (!str_ends_with($privateKey, "\n")) {
        $privateKey .= "\n";
    }
    return $privateKey;
}
function generate_application_name(string $git_repository, string $git_branch, ?string $cuid = null): string
{
    if (is_null($cuid)) {
        $cuid = new Cuid2(7);
    }
    return Str::kebab("$git_repository:$git_branch-$cuid");
}

function is_transactional_emails_active(): bool
{
    return isEmailEnabled(InstanceSettings::get());
}

function set_transanctional_email_settings(InstanceSettings | null $settings = null): string|null
{
    if (!$settings) {
        $settings = InstanceSettings::get();
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
            "transport" => "smtp",
            "host" => data_get($settings, 'smtp_host'),
            "port" => data_get($settings, 'smtp_port'),
            "encryption" => data_get($settings, 'smtp_encryption'),
            "username" => data_get($settings, 'smtp_username'),
            "password" => data_get($settings, 'smtp_password'),
            "timeout" => data_get($settings, 'smtp_timeout'),
            "local_domain" => null,
        ]);
        return 'smtp';
    }
    return null;
}

function base_ip(): string
{
    if (isDev()) {
        return "localhost";
    }
    $settings = InstanceSettings::get();
    if ($settings->public_ipv4) {
        return "$settings->public_ipv4";
    }
    if ($settings->public_ipv6) {
        return "$settings->public_ipv6";
    }
    return "localhost";
}
function getFqdnWithoutPort(String $fqdn)
{
    $url = Url::fromString($fqdn);
    $host = $url->getHost();
    $scheme = $url->getScheme();
    $path = $url->getPath();
    return "$scheme://$host$path";
}
/**
 * If fqdn is set, return it, otherwise return public ip.
 */
function base_url(bool $withPort = true): string
{
    $settings = InstanceSettings::get();
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
            return "http://localhost";
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

function isDev(): bool
{
    return config('app.env') === 'local';
}

function isCloud(): bool
{
    return !config('coolify.self_hosted');
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
        $baseUrl = config('app.name');
        $team = Team::find(0);
        $team->notify(new GeneralNotification("👀 {$baseUrl}: " . $message));
    } catch (\Throwable $e) {
        ray($e->getMessage());
    }
}
function send_user_an_email(MailMessage $mail, string $email, ?string $cc = null): void
{
    $settings = InstanceSettings::get();
    $type = set_transanctional_email_settings($settings);
    if (!$type) {
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
    $isSubscribedToDiscordEvent = data_get($notifiable, "discord_notifications_$event");
    $isSubscribedToTelegramEvent = data_get($notifiable, "telegram_notifications_$event");

    if ($isDiscordEnabled && $isSubscribedToDiscordEvent) {
        $channels[] = DiscordChannel::class;
    }
    if ($isEmailEnabled) {
        $channels[] = EmailChannel::class;
    }
    if ($isTelegramEnabled && $isSubscribedToTelegramEvent) {
        $channels[] = TelegramChannel::class;
    }
    return $channels;
}
function parseEnvFormatToArray($env_file_contents)
{
    $env_array = array();
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
