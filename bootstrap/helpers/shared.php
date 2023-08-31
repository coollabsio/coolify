<?php

use App\Models\InstanceSettings;
use App\Models\Team;
use App\Notifications\Internal\GeneralNotification;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;
use Illuminate\Database\QueryException;
use Illuminate\Mail\Message;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Nubs\RandomNameGenerator\All;
use Poliander\Cron\CronExpression;
use Visus\Cuid2\Cuid2;
use phpseclib3\Crypt\RSA;

function application_configuration_dir(): string
{
    return '/data/coolify/applications';
}

function database_configuration_dir(): string
{
    return '/data/coolify/databases';
}

function backup_dir(): string
{
    return '/data/coolify/backups';
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
function refreshSession(): void
{
    $team = Team::find(currentTeam()->id);
    session(['currentTeam' => $team]);
}
function general_error_handler(Throwable | null $err = null, $that = null, $isJson = false, $customErrorMessage = null): mixed
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
    } catch (Throwable $error) {
        if ($that) {
            return $that->emit('error', $customErrorMessage ?? $error->getMessage());
        } elseif ($isJson) {
            return response()->json([
                'code' => $error->getCode(),
                'error' => $error->getMessage(),
            ]);
        } else {
            ray($customErrorMessage);
            ray($error);
            return $customErrorMessage ?? $error->getMessage();
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
    } catch (Throwable $th) {
        //throw $th;
        ray($th->getMessage());
        return '0.0.0';
    }
}

function generate_random_name(): string
{
    $generator = All::create();
    $cuid = new Cuid2(7);
    return Str::kebab("{$generator->getName()}-$cuid");
}
function generateSSHKey()
{
    $key = RSA::createKey();
    return [
        'private' => $key->toString('PKCS1'),
        'public' => $key->getPublicKey()->toString('OpenSSH',['comment' => 'coolify-generated-ssh-key'])
    ];
}
function formatPrivateKey(string $privateKey) {
    $privateKey = trim($privateKey);
    if (!str_ends_with($privateKey, "\n")) {
        $privateKey .= "\n";
    }
    return $privateKey;
}
function generate_application_name(string $git_repository, string $git_branch): string
{
    $cuid = new Cuid2(7);
    return Str::kebab("$git_repository:$git_branch-$cuid");
}

function is_transactional_emails_active(): bool
{
    return data_get(InstanceSettings::get(), 'smtp_enabled');
}

function set_transanctional_email_settings(InstanceSettings | null $settings = null): void
{
    if (!$settings) {
        $settings = InstanceSettings::get();
    }
    $password = data_get($settings, 'smtp_password');
    if (isset($password)) {
        $password = decrypt($password);
    }

    config()->set('mail.default', 'smtp');
    config()->set('mail.mailers.smtp', [
        "transport" => "smtp",
        "host" => data_get($settings, 'smtp_host'),
        "port" => data_get($settings, 'smtp_port'),
        "encryption" => data_get($settings, 'smtp_encryption'),
        "username" => data_get($settings, 'smtp_username'),
        "password" => $password,
        "timeout" => data_get($settings, 'smtp_timeout'),
        "local_domain" => null,
    ]);
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
        $baseUrl = base_url(false);
        $team = Team::find(0);
        $team->notify(new GeneralNotification("👀 Internal notifications from {$baseUrl}: " . $message));
    } catch (\Throwable $th) {
        ray($th->getMessage());
    }
}
function send_user_an_email(MailMessage $mail, string $email): void
{
    $settings = InstanceSettings::get();
    set_transanctional_email_settings($settings);
    Mail::send(
        [],
        [],
        fn (Message $message) => $message
            ->from(
                data_get($settings, 'smtp_from_address'),
                data_get($settings, 'smtp_from_name')
            )
            ->to($email)
            ->subject($mail->subject)
            ->html((string) $mail->render())
    );
}

