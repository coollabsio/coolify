<?php

namespace App\Support;

final class ContainerName
{
    public static function normalize(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            return '';
        }

        return ltrim($name, '/');
    }
}

