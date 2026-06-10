<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ConfirmPassword extends \Illuminate\Auth\Middleware\RequirePassword
{
    /**
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle($request, Closure $next, ...$parameters): Response
    {
        if (shouldSkipPasswordConfirmation()) {
            return $next($request);
        }

        $redirectToRoute = $parameters[0] ?? null;
        $passwordTimeoutSeconds = $parameters[1] ?? null;

        if ($this->shouldConfirmPassword($request, $passwordTimeoutSeconds)) {
            if ($request->expectsJson()) {
                $request->session()->put('url.intended', passkeyConfirmationIntendedUrl($request));

                return $this->responseFactory->json([
                    'message' => 'Password confirmation required.',
                    'redirect' => $this->urlGenerator->route($redirectToRoute ?: 'password.confirm'),
                ], 423);
            }

            return $this->responseFactory->redirectGuest(
                $this->urlGenerator->route($redirectToRoute ?: 'password.confirm')
            );
        }

        return $next($request);
    }
}
