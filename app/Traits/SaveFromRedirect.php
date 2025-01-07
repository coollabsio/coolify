<?php

namespace App\Traits;

use Illuminate\Support\Collection;

trait SaveFromRedirect
{
    public function saveFromRedirect(string $route, ?Collection $collection = null)
    {
        session()->forget('from');
        if (! $collection || $collection->count() === 0) {
            $collection = $this->parameters;
        }
        $collection = collect($collection) ?? collect([]);
        $queries = collect($this->query) ?? collect([]);
        $collection = $collection->merge($queries);
        session(['from' => [
            'back' => $this->currentRoute,
            'route' => $route,
            'parameters' => $collection,
        ]]);

        return redirect()->route($route);
    }
}
