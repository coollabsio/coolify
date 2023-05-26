<?php

use App\Models\InstanceSettings;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Route;
use Visus\Cuid2\Cuid2;
use Illuminate\Support\Str;

function is_https()
{
    return Str::of(InstanceSettings::get()->fqdn)->startsWith('https');
}
function general_error_handler(\Throwable $e, $that = null, $isJson = false)
{
    try {
        if ($e instanceof QueryException) {
            if ($e->errorInfo[0] === '23505') {
                throw new \Exception('Duplicate entry found.', '23505');
            } else if (count($e->errorInfo) === 4) {
                throw new \Exception($e->errorInfo[3]);
            } else {
                throw new \Exception($e->errorInfo[2]);
            }
        } else {
            throw new \Exception($e->getMessage());
        }
    } catch (\Throwable $error) {
        if ($that) {
            return $that->emit('error', $error->getMessage());
        } elseif ($isJson) {
            return response()->json([
                'code' => $error->getCode(),
                'error' => $error->getMessage(),
            ]);
        } else {
            // dump($error);
        }
    }
}

function get_parameters()
{
    return Route::current()->parameters();
}

function get_latest_version_of_coolify()
{
    $response = Http::get('https://coolify-cdn.b-cdn.net/versions.json');
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
