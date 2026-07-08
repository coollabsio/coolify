<?php

use Illuminate\Support\Str;

function normalize_email_identity(?string $email): ?string
{
    if (blank($email) || ! str_contains($email, '@')) {
        return null;
    }

    [$localPart, $domain] = explode('@', Str::lower($email), 2);
    $localPart = Str::before($localPart, '+');
    $localPart = str_replace('.', '', $localPart);

    if (blank($localPart) || blank($domain)) {
        return null;
    }

    return $localPart.'@'.$domain;
}
