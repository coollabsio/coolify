<?php

namespace App\Exceptions;

use App\Models\InstanceSettings;
use App\Models\User;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
use RuntimeException;
use Sentry\Laravel\Integration;
use Sentry\State\Scope;
use Throwable;

class Handler extends ExceptionHandler
{
    /**
     * A list of exception types with their corresponding custom log levels.
     *
     * @var array<class-string<\Throwable>, \Psr\Log\LogLevel::*>
     */
    protected $levels = [
        //
    ];

    /**
     * A list of the exception types that are not reported.
     *
     * @var array<int, class-string<\Throwable>>
     */
    protected $dontReport = [
        ProcessException::class,
        NonReportableException::class,
        DeploymentException::class,
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

    private InstanceSettings $settings;

    protected function unauthenticated($request, AuthenticationException $exception)
    {
        if ($request->is('api/*') || $request->expectsJson() || $this->shouldReturnJson($request, $exception)) {
            return response()->json(['message' => $exception->getMessage()], 401);
        }

        return redirect()->guest($exception->redirectTo($request) ?? route('login'));
    }

    /**
     * Render an exception into an HTTP response.
     */
    public function render($request, Throwable $e)
    {
        // Handle authorization exceptions for API routes
        if ($e instanceof \Illuminate\Auth\Access\AuthorizationException) {
            if ($request->is('api/*') || $request->expectsJson()) {
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

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            if (isDev()) {
                return;
            }
            if ($e instanceof RuntimeException) {
                return;
            }
            $this->settings = instanceSettings();
            if ($this->settings->do_not_track) {
                return;
            }
            app('sentry')->configureScope(
                function (Scope $scope) {
                    $email = auth()?->user() ? auth()->user()->email : 'guest';
                    $instanceAdmin = User::find(0)->email ?? 'admin@localhost';
                    $scope->setUser(
                        [
                            'email' => $email,
                            'instanceAdmin' => $instanceAdmin,
                        ]
                    );
                }
            );
            // Check for errors that should not be reported to Sentry
            if (str($e->getMessage())->contains('No space left on device')) {
                // Log locally but don't send to Sentry
                logger()->warning('Disk space error: '.$e->getMessage());

                return;
            }

            Integration::captureUnhandledException($e);
        });
    }
}
