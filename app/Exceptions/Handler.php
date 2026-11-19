<?php

namespace App\Exceptions;

use App\Providers\RouteServiceProvider;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use Illuminate\Session\TokenMismatchException;
use Psr\Log\LogLevel;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of exception types with their corresponding custom log levels.
     *
     * @var array<class-string<Throwable>, LogLevel::*>
     */
    protected $levels = [
        //
    ];

    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<Throwable>>
     */
    protected $dontReport = [
        ProcessException::class,
        NonReportableException::class,
        DeploymentException::class,
        DatabaseStartException::class,
    ];

    /**
     * A list of the inputs that are never flashed to the session on validation exceptions.
     *
     * @var array<int, string>
     */
    protected $dontFlash = [
        'current_password',
        'password',
        'password_confirmation',
    ];

    protected function unauthenticated($request, AuthenticationException $exception)
    {
        if ($request->is('api/*') || $request->expectsJson() || $this->shouldReturnJson($request, $exception)) {
            if ($request->is('api/*')) {
                auditLog('api.auth.unauthenticated', [
                    'reason' => $exception->getMessage(),
                    'guards' => $exception->guards(),
                ], 'warning');
            }

            return response()->json(['message' => $exception->getMessage()], 401);
        }

        return redirect()->guest($exception->redirectTo($request) ?? route('login'));
    }

    /**
     * Render an exception into an HTTP response.
     */
    public function render($request, Throwable $e)
    {
        // A duplicate login or 2FA submission carries a stale token on an already authenticated session, see https://github.com/coollabsio/coolify/issues/10670
        if ($e instanceof TokenMismatchException && $request->routeIs('login.store', 'two-factor.login.store') && $request->user()) {
            return redirect()->intended(RouteServiceProvider::HOME);
        }

        // Handle authorization exceptions for API routes. Exceptions carrying
        // an explicit status (e.g. denyAsNotFound) keep it via parent::render.
        if ($e instanceof AuthorizationException && ! $e->hasStatus()) {
            if ($request->is('api/*') || $request->expectsJson()) {
                if ($request->is('api/*')) {
                    auditLog('api.auth.policy_denied', [
                        'reason' => $e->getMessage(),
                        'route' => $request->route()?->getName() ?? $request->path(),
                    ], 'warning');
                }

                // Get the custom message from the policy if available
                $message = $e->getMessage();

                // Clean up the message for API responses (remove HTML tags if present)
                $message = strip_tags(str_replace('<br/>', ' ', $message));

                // If no custom message, use a default one
                if (empty($message) || $message === 'This action is unauthorized.') {
                    $message = 'You are not authorized to perform this action.';
                }

                return response()->json([
                    'message' => $message,
                    'error' => 'Unauthorized',
                ], 403);
            }
        }

        return parent::render($request, $e);
    }
}
