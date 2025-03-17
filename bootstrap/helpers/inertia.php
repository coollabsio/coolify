<?php

use Spatie\Url\Url;

/**
 * Create a new redirect response to a named route - customized for Coolify
 *
 * @param  \BackedEnum|string  $route
 * @param  mixed  $parameters
 * @param  int  $status
 * @param  array  $headers
 * @return \Illuminate\Http\RedirectResponse
 */
function goto_route($route, $parameters = [], $status = 302, $headers = [])
{
    $url = route($route, $parameters, $status, $headers);
    $origin = Request()->header('origin');
    if (filled($origin)) {
        $originUrl = Url::fromString($origin);
        $urlUrl = Url::fromString($url);
        $url = $urlUrl->withScheme($originUrl->getScheme())->__toString();
    }

    return redirect()->to($url);
}
