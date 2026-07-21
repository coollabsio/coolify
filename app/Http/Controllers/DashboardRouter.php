<?php

namespace App\Http\Controllers;

use App\Http\Controllers\V4\DashboardController as V4DashboardController;
use App\Livewire\Dashboard as LivewireDashboard;
use App\Support\V4\UiMode;
use Illuminate\Contracts\Support\Responsable;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the classic Livewire dashboard or the Next (Inertia) dashboard
 * based on the user's {@see UiMode} preference.
 */
class DashboardRouter extends Controller
{
    public function __invoke(Request $request): Response|Responsable
    {
        if (UiMode::isNext($request)) {
            return app(V4DashboardController::class)($request);
        }

        return app(LivewireDashboard::class)();
    }
}
