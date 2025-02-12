<?php

namespace App\Http\Controllers;

use Inertia\Inertia;

class InertiaController extends Controller
{
    public function show()
    {
        return Inertia::render('Dashboard', [
            'user' => 'test',
        ]);
    }
}
