<?php

namespace App\Support\V4;

use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Cookie;

enum UiMode: string
{
    case Classic = 'classic';
    case Next = 'next';

    public const SESSION_KEY = 'ui_mode';

    public const COOKIE_KEY = 'ui_mode';

    public static function current(?Request $request = null): self
    {
        $request ??= request();

        $value = $request->session()->get(self::SESSION_KEY)
            ?? $request->cookie(self::COOKIE_KEY)
            ?? self::Classic->value;

        return self::tryFrom((string) $value) ?? self::Classic;
    }

    public static function isNext(?Request $request = null): bool
    {
        return self::current($request) === self::Next;
    }

    public static function isClassic(?Request $request = null): bool
    {
        return self::current($request) === self::Classic;
    }

    public static function set(self $mode, Request $request): void
    {
        $request->session()->put(self::SESSION_KEY, $mode->value);

        cookie()->queue(Cookie::create(self::COOKIE_KEY)
            ->withValue($mode->value)
            ->withExpires(now()->addYear())
            ->withPath('/')
            ->withSecure($request->secure())
            ->withHttpOnly(false)
            ->withSameSite('lax'));
    }
}
