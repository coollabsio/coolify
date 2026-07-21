<?php

namespace App\Http\Controllers\V4;

use App\Http\Controllers\Controller;
use App\Support\Dashboard\DashboardData;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Next (Inertia) dashboard for Coolify v4.
 *
 * Uses the same data as {@see \App\Livewire\Dashboard} via {@see DashboardData}.
 */
class DashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('Dashboard', DashboardData::forInertia());
    }
}
