<?php

namespace App\Http\Controllers;

use App\Support\V4\UiMode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Persist UI mode and always land on the dashboard with a full browser reload.
 *
 * Classic Livewire and Next Inertia shells are different root documents; soft
 * Inertia visits leave a hybrid page. Non-Inertia form POSTs use a normal
 * redirect; Inertia requests use {@see Inertia::location()} so the client
 * performs a full window navigation.
 */
class UiModeController extends Controller
{
    public function update(Request $request): RedirectResponse|HttpResponse|Response
    {
        $validated = $request->validate([
            'mode' => ['required', 'string', Rule::enum(UiMode::class)],
        ]);

        UiMode::set(UiMode::from($validated['mode']), $request);

        $dashboardUrl = route('dashboard');

        // Force a full document load when the switch came from an Inertia page.
        if ($request->header('X-Inertia')) {
            return Inertia::location($dashboardUrl);
        }

        return redirect()->to($dashboardUrl);
    }
}
