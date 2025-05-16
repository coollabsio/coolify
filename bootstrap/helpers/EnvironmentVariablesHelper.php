<?php

namespace Bootstrap\Helpers;

use Illuminate\Support\Str;
use Nubs\RandomNameGenerator\Alliteration;
use Ramsey\Uuid\Uuid;

class EnvironmentVariablesHelper
{
    public static function generateCoolifyMagicEnvValues(string $key): ?string
    {
        ray('Generating Coolify Magic Value')->label('Start');
        ray($key)->label('Input Key');

        $parts = explode('_', $key);
        ray($parts)->label('Exploded Parts');

        if (count($parts) < 3 || $parts[0] !== 'COOLIFY') {
            ray('Invalid format - returning null')->color('red');

            return null;
        }

        array_shift($parts);

        $typeIndex = null;
        $knownTypes = ['SECRET', 'USER', 'ID', 'UUID'];
        ray($knownTypes)->label('Known Types');

        for ($i = count($parts) - 1; $i >= 0; $i--) {
            ray("Checking part {$parts[$i]} at index {$i}")->purple();
            if (in_array($parts[$i], $knownTypes)) {
                $typeIndex = $i;
                ray("Found type {$parts[$i]} at index {$i}")->green();
                break;
            }
        }

        if ($typeIndex === null) {
            ray('No valid type found - returning null')->color('red');

            return null;
        }

        $service = implode('_', array_slice($parts, 0, $typeIndex));
        ray($service)->label('Service Name');

        $typeAndConfig = array_slice($parts, $typeIndex);
        ray($typeAndConfig)->label('Type and Config');

        $result = match ($typeAndConfig[0]) {
            'SECRET' => self::handleSecret($typeAndConfig),
            'USER' => self::handleUser($typeAndConfig),
            'ID' => self::handleId($typeAndConfig),
            'UUID' => Uuid::uuid4()->toString(),
            default => null
        };

        ray($result)->label('Generated Result');

        return $result;
    }

    private static function handleSecret(array $parts): string
    {
        ray($parts)->label('Handle Secret - Input Parts');

        $type = $parts[1] ?? '';
        $length = (int) end($parts);

        ray([
            'type' => $type,
            'length' => $length,
        ])->label('Secret Parameters');

        if ($length <= 0) {
            $length = 32; // Default length
            ray("Using default length: {$length}")->yellow();
        }

        $result = match ($type) {
            'FULL' => Str::password($length, symbols: true),
            'UP' => self::generateRandomString($length, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789'),
            'LOW' => self::generateRandomString($length, 'abcdefghijklmnopqrstuvwxyz0123456789'),
            'STR' => self::generateRandomString($length, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ'),
            'STR_UP' => self::generateRandomString($length, 'ABCDEFGHIJKLMNOPQRSTUVWXYZ'),
            'STR_LOW' => self::generateRandomString($length, 'abcdefghijklmnopqrstuvwxyz'),
            'INT' => self::generateRandomString($length, '0123456789'),
            'HEX' => bin2hex(random_bytes(intdiv($length, 2))),
            'HEX_UP' => self::generateRandomString($length, 'ABCDEF0123456789'),
            'HEX_LOW' => self::generateRandomString($length, 'abcdef0123456789'),
            'HEX_STR' => self::generateRandomString($length, 'abcdefABCDEF'),
            'HEX_STR_UP' => self::generateRandomString($length, 'ABCDEF'),
            'HEX_STR_LOW' => self::generateRandomString($length, 'abcdef'),
            default => self::generateRandomString($length, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789')
        };

        ray($result)->label('Generated Secret');

        return $result;
    }

    private static function handleUser(array $parts): string
    {
        if (count($parts) < 2) {
            return Str::random(32);
        }

        $type = $parts[1] ?? '';
        $length = (int) end($parts);

        if ($length <= 0) {
            $length = 32;
        }

        return match ($type) {
            'FULL' => Str::password($length, symbols: true),
            'STR' => self::generateRandomString($length, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ'),
            'REAL' => self::generateRealWordUsername($length),
            default => Str::random($length)
        };
    }

    private static function handleId(array $parts): string
    {
        $min = 1;
        $max = 1000;

        foreach ($parts as $index => $part) {
            if ($part === 'MIN' && isset($parts[$index + 1])) {
                $min = (int) $parts[$index + 1];
            }
            if ($part === 'MAX' && isset($parts[$index + 1])) {
                $max = (int) $parts[$index + 1];
            }
        }

        return (string) random_int($min, $max);
    }

    private static function generateRandomString(int $length, string $characters): string
    {
        ray([
            'length' => $length,
            'characters' => $characters,
        ])->label('Generate Random String - Input');

        $string = '';
        $max = strlen($characters) - 1;

        while (($len = strlen($string)) < $length) {
            $size = $length - $len;
            $bytes = random_bytes($size);
            $string .= substr(str_replace(
                ['/', '+', '='],
                '',
                base64_encode($bytes)
            ), 0, $size);
        }

        $string = preg_replace('/[^'.preg_quote($characters, '/').']/', '', $string);

        while (strlen($string) < $length) {
            $string .= $characters[random_int(0, $max)];
        }

        $result = substr($string, 0, $length);
        ray($result)->label('Generated Random String');

        return $result;
    }

    private static function generateRealWordUsername(int $wordCount): string
    {
        $generator = new Alliteration;
        $words = [];

        for ($i = 0; $i < $wordCount; $i++) {
            $words[] = strtolower($generator->getName());
        }

        return implode('-', $words);
    }
}
