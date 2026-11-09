<?php

namespace App\Enums;

enum ImageScanErrorCode: string
{
    case DiskSpace = 'disk_space';
    case DbTooOld = 'db_too_old';
    case DbMissing = 'db_missing';
    case Timeout = 'timeout';
    case OutOfMemory = 'out_of_memory';
    case LockBusy = 'lock_busy';
    case RegistryAuth = 'registry_auth';
    case BuildServerOff = 'build_server_off';
    case ScanningOff = 'scanning_off';
    case CustomCommand = 'custom_command';
    case ImageMissing = 'image_missing';
    case PullFailed = 'pull_failed';
    case Truncated = 'truncated';
    case NoPackageDatabase = 'no_package_database';
    case Unknown = 'unknown';

    /**
     * Fixed user-facing message. Raw command output is never shown.
     *
     * @param  array{server?: string, n?: int|string}  $context
     */
    public function message(array $context = []): string
    {
        $server = (string) ($context['server'] ?? 'the server');
        $n = (string) ($context['n'] ?? '?');

        return match ($this) {
            self::DiskSpace => isset($context['n']) ? "Not enough disk space on {$server}: {$n} GB needed." : "Not enough disk space on {$server}.",
            self::DbTooOld => "The vulnerability database is {$n} days old. {$server} cannot reach grype.anchore.io.",
            self::DbMissing => "The vulnerability database is not on {$server} yet. Wait for the first update to finish or check that {$server} can reach grype.anchore.io.",
            self::Timeout => 'The scan took too long and was stopped.',
            self::OutOfMemory => 'The scanner ran out of memory and was stopped. It can use up to 1 GB, and the server must have that much free memory.',
            self::LockBusy => "Another scan is still running on {$server}.",
            self::RegistryAuth => "Coolify cannot read this image from its registry. Log in to the registry on {$server}.",
            self::BuildServerOff => 'Image scanning is off on the build server.',
            self::ScanningOff => "Turn on image scanning on {$server}.",
            self::CustomCommand => 'Coolify cannot check the image because this application uses a custom build or start command.',
            self::ImageMissing => "The image is not on {$server}. Click Scan now to pull and scan it. An image that Coolify builds needs a new deploy.",
            self::PullFailed => "Coolify could not pull the image to {$server}. Check the image name, and log in to its registry on {$server} if it is private.",
            self::Truncated => 'The scan result was too large to read completely.',
            self::NoPackageDatabase => "Coolify found no package database of a supported package manager (dpkg, rpm, apk, pacman) on {$server}.",
            self::Unknown => 'The scan could not run.',
        };
    }
}
