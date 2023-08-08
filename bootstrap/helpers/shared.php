<?php

use App\Models\InstanceSettings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Visus\Cuid2\Cuid2;

function general_error_handler(\Throwable|null $err = null, $that = null, $isJson = false, $customErrorMessage = null)
{
    try {
        ray('ERROR OCCURED: ' . $err->getMessage());
        if ($err instanceof QueryException) {
            if ($err->errorInfo[0] === '23505') {
                throw new \Exception($customErrorMessage ?? 'Duplicate entry found.', '23505');
            } else if (count($err->errorInfo) === 4) {
                throw new \Exception($customErrorMessage ?? $err->errorInfo[3]);
            } else {
                throw new \Exception($customErrorMessage ?? $err->errorInfo[2]);
            }
        } else {
            throw new \Exception($customErrorMessage ?? $err->getMessage());
        }
    } catch (\Throwable $error) {
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
        }
    }
}

function getRouteParameters()
{
    return Route::current()->parameters();
}

function get_latest_version_of_coolify()
{
    try {
        $response = Http::get('https://cdn.coollabs.io/coolify/versions.json');
        $versions = $response->json();
        return data_get($versions, 'coolify.v4.version');
    } catch (\Throwable $th) {
        //throw $th;
        ray($th->getMessage());
        return '0.0.0';
    }
}

function generate_random_name()
{
    $generator = \Nubs\RandomNameGenerator\All::create();
    $cuid = new Cuid2(7);
    return Str::kebab("{$generator->getName()}-{$cuid}");
}

function generate_application_name(string $git_repository, string $git_branch)
{
    $cuid = new Cuid2(7);
    return Str::kebab("{$git_repository}:{$git_branch}-{$cuid}");
}

function is_transactional_emails_active()
{
    return data_get(InstanceSettings::get(), 'smtp_enabled');
}

function set_transanctional_email_settings()
{
    $settings = InstanceSettings::get();
    $password = data_get($settings, 'smtp_password');
    if ($password) $password = decrypt($password);

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

function base_ip()
{
    if (isDev()) {
        return "http://localhost";
    }
    $settings = InstanceSettings::get();
    return "http://{$settings->public_ipv4}";
}

function base_url(bool $withPort = true)
{
    $settings = InstanceSettings::get();
    if ($settings->fqdn) {
        return $settings->fqdn;
    }
    $port = config('app.port');
    if ($settings->public_ipv4) {
        if ($withPort) {
            if (isDev()) {
                return "http://localhost:{$port}";
            }
            return "http://{$settings->public_ipv4}:{$port}";
        }
        if (isDev()) {
            return "http://localhost";
        }
        return "http://{$settings->public_ipv4}";
    }
    if ($settings->public_ipv6) {
        if ($withPort) {
            return "http://{$settings->public_ipv6}:{$port}";
        }
        return "http://{$settings->public_ipv6}";
    }
    return url('/');
}

function isDev()
{
    return config('app.env') === 'local';
}

function isCloud()
{
    return !config('coolify.self_hosted');
}
