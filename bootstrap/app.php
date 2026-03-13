<?php

use App\Console\ScheduleConfiguration;
use App\Exceptions\DeploymentException;
use App\Exceptions\NonReportableException;
use App\Exceptions\ProcessException;
use App\Http\Middleware\CheckForcePasswordReset;
use App\Http\Middleware\DecideWhatToDoWithUser;
use App\Http\Middleware\TrustHosts;
use App\Http\Middleware\TrustProxies;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Sentry\Laravel\Integration;
use Sentry\State\Scope;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function () {
            Route::prefix('webhooks')
                ->group(base_path('routes/webhooks.php'));
        },
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->use([
            TrustHosts::class,
            TrustProxies::class,
            \Illuminate\Http\Middleware\HandleCors::class,
            \Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance::class,
            \Illuminate\Foundation\Http\Middleware\ValidatePostSize::class,
            \Illuminate\Foundation\Http\Middleware\TrimStrings::class,
            \Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull::class,
        ]);

        $middleware->web(append: [
            CheckForcePasswordReset::class,
            DecideWhatToDoWithUser::class,
        ]);

        $middleware->validateCsrfTokens(except: ['webhooks/*']);
        $middleware->preventRequestsDuringMaintenance(except: ['webhooks/*', '/api/health']);

        $middleware->redirectGuestsTo('/login');
        $middleware->redirectUsersTo('/');

        $middleware->alias([
            'api.ability' => \App\Http\Middleware\ApiAbility::class,
            'api.sensitive' => \App\Http\Middleware\ApiSensitiveData::class,
            'can.create.resources' => \App\Http\Middleware\CanCreateResources::class,
            'can.update.resource' => \App\Http\Middleware\CanUpdateResource::class,
            'can.access.terminal' => \App\Http\Middleware\CanAccessTerminal::class,
        ]);
    })
    ->withSchedule(function (Schedule $schedule) {
        (new ScheduleConfiguration($schedule))->configure();
    })
    ->withExceptions(function (Exceptions $exceptions) {
        $exceptions->dontReport([
            ProcessException::class,
            NonReportableException::class,
            DeploymentException::class,
        ]);

        $exceptions->dontFlash([
            'current_password',
            'password',
            'password_confirmation',
        ]);

        $exceptions->render(function (AuthenticationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 401);
            }

            return redirect()->guest($e->redirectTo($request) ?? route('login'));
        });

        $exceptions->render(function (AuthorizationException $e, Request $request) {
            if ($request->is('api/*') || $request->expectsJson()) {
                $message = strip_tags(str_replace('<br/>', ' ', $e->getMessage()));

                if (empty($message) || $message === 'This action is unauthorized.') {
                    $message = 'You are not authorized to perform this action.';
                }

                return response()->json([
                    'message' => $message,
                    'error' => 'Unauthorized',
                ], 403);
            }
        });

        $exceptions->report(function (Throwable $e) {
            if (isDev()) {
                return false;
            }
            if ($e instanceof RuntimeException) {
                return false;
            }
            $settings = instanceSettings();
            if ($settings->do_not_track) {
                return false;
            }
            app('sentry')->configureScope(
                function (Scope $scope) {
                    $email = auth()?->user() ? auth()->user()->email : 'guest';
                    $instanceAdmin = User::find(0)->email ?? 'admin@localhost';
                    $scope->setUser([
                        'email' => $email,
                        'instanceAdmin' => $instanceAdmin,
                    ]);
                }
            );

            if (str($e->getMessage())->contains('No space left on device')) {
                logger()->warning('Disk space error: '.$e->getMessage());

                return false;
            }

            Integration::captureUnhandledException($e);
        });
    })
    ->create();
