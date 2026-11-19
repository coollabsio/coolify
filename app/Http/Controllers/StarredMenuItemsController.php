<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;

/**
 * Saves the ordered starred pages of one resource menu for the current user.
 * A star is a route name and applies to every resource of that menu type.
 */
class StarredMenuItemsController extends Controller
{
    /** @var array<string, string> Route name prefix of the pages in each starrable menu */
    public const MENUS = [
        'application' => 'project.application.',
        'service' => 'project.service.',
        'database' => 'project.database.',
        'server' => 'server.',
    ];

    public function __invoke(Request $request): Response
    {
        $validated = Validator::make($request->all(), [
            'menu' => ['required', 'string', 'in:'.implode(',', array_keys(self::MENUS))],
            'items' => ['present', 'array', 'max:50'],
            'items.*' => ['required', 'string', 'distinct', 'max:100'],
        ])->validate();

        $prefix = self::MENUS[$validated['menu']];
        $items = array_values(array_filter(
            $validated['items'],
            fn (string $routeName): bool => Str::startsWith($routeName, $prefix) && Route::has($routeName),
        ));

        // Lock the row so that concurrent saves of other menus (e.g. from another tab) are not lost.
        DB::transaction(function () use ($request, $validated, $items): void {
            $user = User::query()->lockForUpdate()->findOrFail($request->user()->id);
            $starred = $user->starred_menu_items ?? [];
            $starred[$validated['menu']] = $items;
            $user->starred_menu_items = $starred;
            $user->save();
        });

        return response()->noContent();
    }
}
