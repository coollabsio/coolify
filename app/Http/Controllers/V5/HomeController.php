<?php

namespace App\Http\Controllers\V5;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Models\User;
use App\Services\Flux\FluxHealth;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class HomeController extends Controller
{
    public function __invoke(Request $request, FluxHealth $fluxHealth): Response
    {
        /** @var User $user */
        $user = $request->user();
        $currentTeam = $request->attributes->get('v5.currentTeam');

        return Inertia::render('Home', [
            'status' => 'v5-ready',
            'flux' => $fluxHealth->check(),
            'cooldHosts' => $this->cooldHosts(),
            'currentTeam' => $currentTeam instanceof Team ? [
                'id' => $currentTeam->id,
                'name' => $currentTeam->name,
                'description' => $currentTeam->description,
                'role' => $currentTeam->pivot?->role ?? $user->roleInTeam($currentTeam->id),
                'personal' => $currentTeam->personal_team,
            ] : null,
            'teams' => $user->teams()
                ->select('teams.id', 'teams.name', 'teams.description', 'teams.personal_team')
                ->orderBy('teams.name')
                ->get()
                ->map(fn (Team $team) => [
                    'id' => $team->id,
                    'name' => $team->name,
                    'description' => $team->description,
                    'role' => $team->pivot->role,
                    'personal' => $team->personal_team,
                ]),
        ]);
    }

    /**
     * @return array<int, array{id: string, wireguardIp: string|null, capabilities: array<int, string>, builderEnabled: bool, builderCapacity: int}>
     */
    private function cooldHosts(): array
    {
        $baseId = (string) config('coold.dev_host_id');
        $count = max(0, (int) config('coold.dev_host_count'));
        $builderCapacity = (int) config('coold.dev_builder_capacity');
        $builderEnabled = $builderCapacity > 0;

        if ($count === 0 || $baseId === '') {
            return [];
        }

        return collect(range(1, $count))
            ->map(fn (int $index) => [
                'id' => $index === 1 ? $baseId : (string) config("coold.dev_host_id_{$index}", "{$baseId}-{$index}"),
                'wireguardIp' => (string) config("coold.dev_wireguard_ip_{$index}") ?: null,
                'capabilities' => $builderEnabled ? ['coold', 'builder'] : ['coold'],
                'builderEnabled' => $builderEnabled,
                'builderCapacity' => $builderCapacity,
            ])
            ->all();
    }
}
