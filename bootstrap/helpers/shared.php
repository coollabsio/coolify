<?php

use App\Models\InstanceSettings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Visus\Cuid2\Cuid2;
use Illuminate\Support\Str;

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

function get_parameters()
{
    return Route::current()->parameters();
}

function get_latest_version_of_coolify()
{
    $response = Http::get('https://cdn.coollabs.io/coolify/versions.json');
    $versions = $response->json();
    return data_get($versions, 'coolify.v4.version');
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
    return data_get(InstanceSettings::get(), 'extra_attributes.smtp_host');
}

function set_transanctional_email_settings()
{
    $settings = InstanceSettings::get();
    config()->set('mail.default', 'smtp');
    config()->set('mail.mailers.smtp', [
        "transport" => "smtp",
        "host" => $settings->extra_attributes?->get('smtp_host'),
        "port" => $settings->extra_attributes?->get('smtp_port'),
        "encryption" => $settings->extra_attributes?->get('smtp_encryption'),
        "username" => $settings->extra_attributes?->get('smtp_username'),
        "password" => $settings->extra_attributes?->get('smtp_password'),
        "timeout" => $settings->extra_attributes?->get('smtp_timeout'),
        "local_domain" => null,
    ]);
}
