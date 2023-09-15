<?php

namespace App\Exceptions;

use App\Models\InstanceSettings;
use Illuminate\Foundation\Exceptions\Handler as ExceptionHandler;
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
        ProcessException::class
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

    /**
     * Register the exception handling callbacks for the application.
     */
    public function register(): void
    {
        $this->reportable(function (Throwable $e) {
            if (isDev()) {
                return;
            }
            $this->settings = InstanceSettings::get();
            if ($this->settings->do_not_track) {
                return;
            }
            app('sentry')->configureScope(
                function (Scope $scope) {
                    $scope->setUser(
                        [
                            'id' => config('sentry.server_name'),
                            'email' => auth()->user()->email
                        ]
                    );
                }
            );
            Integration::captureUnhandledException($e);
        });
    }
}
