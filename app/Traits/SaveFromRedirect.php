<?php

namespace App\Traits;

use Illuminate\Support\Collection;

trait SaveFromRedirect
{
    public function saveFromRedirect(string $route, ?Collection $parameters = null)
    {
        session()->forget('from');
        if (! $parameters || $parameters->count() === 0) {
            $parameters = $this->parameters;
        }
        $parameters = collect($parameters) ?? collect([]);
        $queries = collect($this->query) ?? collect([]);
        $parameters = $parameters->merge($queries);
        session(['from' => [
            'back' => $this->currentRoute,
            'route' => $route,
            'parameters' => $parameters,
        ]]);

        return redirect()->route($route);
    }
}
