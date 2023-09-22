<?php

use App\Models\Service;
use Illuminate\Support\Str;

function replaceRegex(?string $name = null)
{
    return "/\\\${?{$name}[^}]*}?|\\\${$name}\w+/";
}
function collectRegex(string $name)
{
    return "/{$name}\w+/";
}
function replaceVariables($variable)
{
    return $variable->replaceFirst('$', '')->replaceFirst('{', '')->replaceLast('}', '');
}

function serviceStatus(Service $service)
{
    $foundRunning = false;
    $isDegraded = false;
    $applications = $service->applications;
    $databases = $service->databases;
    foreach ($applications as $application) {
        if (Str::of($application->status)->startsWith('running')) {
            $foundRunning = true;
        } else {
            $isDegraded = true;
        }
    }
    foreach ($databases as $database) {
        if (Str::of($database->status)->startsWith('running')) {
            $foundRunning = true;
        } else {
            $isDegraded = true;
        }
    }
    if ($foundRunning && !$isDegraded) {
        return 'running';
    } else if ($foundRunning && $isDegraded) {
        return 'degraded';
    } else if (!$foundRunning && $isDegraded) {
        return 'exited';
    }
}
