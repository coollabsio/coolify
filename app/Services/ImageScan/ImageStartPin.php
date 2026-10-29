<?php

namespace App\Services\ImageScan;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * The exact build of a "Pull latest" security update: the digest that Coolify scanned, waiting for the next start of the
 * resource that runs it (a Docker image or Compose app, a standalone database, or a service). The start pulls that
 * digest and gives it the configured tag, so the containers keep the tag (`mysql:8`) but run the scanned build, even
 * when the publisher pushed another build since. The configuration never changes, so later starts pull the tag again.
 */
final class ImageStartPin
{
    /** A queued deploy can wait; after this the start pulls the tag as usual and the scan after it checks the result. */
    private const TTL_HOURS = 6;

    /**
     * @param  string  $ref  The image as the start names it (`mysql:8`, the scanned image ref).
     * @param  string  $digest  The scanned digest of the newer build (`sha256:…`).
     */
    public static function put(Model $owner, string $ref, string $digest): void
    {
        $image = ImageRef::parse($ref);
        if ($image->digest !== null || ! ImageRef::isValidDigest($digest)) {
            throw new InvalidArgumentException('A pin needs an image tag and a digest.');
        }

        $pins = self::peek($owner);
        $pins[self::normalize($ref)] = ['ref' => $ref, 'pull' => $image->qualifiedName().'@'.$digest];
        Cache::put(self::key($owner), $pins, now()->addHours(self::TTL_HOURS));
    }

    /**
     * The pins of the owner, which the start uses up.
     *
     * @return array<string, array{ref: string, pull: string}> Keyed by the normalized image ref.
     */
    public static function take(Model $owner): array
    {
        $pins = Cache::pull(self::key($owner));

        return is_array($pins) ? $pins : [];
    }

    public static function forget(Model $owner): void
    {
        Cache::forget(self::key($owner));
    }

    /**
     * Only the pins of the given images (as a start names them), for a start that runs some of them.
     *
     * @param  array<string, array{ref: string, pull: string}>  $pins
     * @param  iterable<string>  $images
     * @return array<string, array{ref: string, pull: string}>
     */
    public static function for(array $pins, iterable $images): array
    {
        $names = [];
        foreach ($images as $image) {
            $names[self::normalize((string) $image)] = true;
        }

        return array_intersect_key($pins, $names);
    }

    /**
     * Pulls the exact build and gives it the configured tag. `$prefix` is the Docker config prefix of the server.
     *
     * @param  array<string, array{ref: string, pull: string}>  $pins
     * @return list<string>
     */
    public static function commands(array $pins, string $prefix = ''): array
    {
        $commands = [];
        foreach ($pins as $pin) {
            $commands[] = 'echo '.escapeshellarg("Pulling the scanned build of {$pin['ref']}.");
            $commands[] = "{$prefix}docker pull ".escapeshellarg($pin['pull'])." && {$prefix}docker tag ".escapeshellarg($pin['pull']).' '.escapeshellarg($pin['ref']);
        }

        return $commands;
    }

    /**
     * `mysql:8`, `docker.io/library/mysql:8`, and `index.docker.io/mysql:8` are the same image.
     */
    private static function normalize(string $ref): string
    {
        try {
            $image = ImageRef::parse(trim($ref));
        } catch (InvalidArgumentException) {
            return trim($ref);
        }

        return $image->qualifiedName().':'.($image->tag ?? 'latest');
    }

    /**
     * @return array<string, array{ref: string, pull: string}>
     */
    private static function peek(Model $owner): array
    {
        $pins = Cache::get(self::key($owner));

        return is_array($pins) ? $pins : [];
    }

    private static function key(Model $owner): string
    {
        return 'image-scan:start-pin:'.$owner->getMorphClass().':'.$owner->getKey();
    }
}
