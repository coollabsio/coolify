<?php

use App\Models\InstanceSettings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Nubs\RandomNameGenerator\All;
use Poliander\Cron\CronExpression;
use Visus\Cuid2\Cuid2;
use DanHarrin\LivewireRateLimiting\Exceptions\TooManyRequestsException;

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

function is_instance_admin()
{
    return auth()->user()?->isInstanceAdmin();
}

function general_error_handler(Throwable|null $err = null, $that = null, $isJson = false, $customErrorMessage = null): mixed
{
    try {
        ray('ERROR OCCURRED: ' . $err->getMessage());
        if ($err instanceof QueryException) {
            if ($err->errorInfo[0] === '23505') {
                throw new Exception($customErrorMessage ?? 'Duplicate entry found.', '23505');
            } else if (count($err->errorInfo) === 4) {
                throw new Exception($customErrorMessage ?? $err->errorInfo[3]);
            } else {
                throw new Exception($customErrorMessage ?? $err->errorInfo[2]);
            }
        } elseif($err instanceof TooManyRequestsException){
            throw new Exception($customErrorMessage ?? "Too many requests. Please try again in {$err->secondsUntilAvailable} seconds.");
        }else {
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

function generate_application_name(string $git_repository, string $git_branch): string
{
    $cuid = new Cuid2(7);
    return Str::kebab("$git_repository:$git_branch-$cuid");
}

function is_transactional_emails_active(): bool
{
    return data_get(InstanceSettings::get(), 'smtp_enabled');
}

function set_transanctional_email_settings(InstanceSettings|null $settings = null): void
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
    if (is_dev()) {
        return "http://localhost";
    }
    $settings = InstanceSettings::get();
    return "http://$settings->public_ipv4";
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
            if (is_dev()) {
                return "http://localhost:$port";
            }
            return "http://$settings->public_ipv4:$port";
        }
        if (is_dev()) {
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

function is_dev(): bool
{
    return config('app.env') === 'local';
}

function is_cloud(): bool
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
