<?php

namespace App\Livewire\SharedVariables;

use App\Livewire\Concerns\RemembersCanvasView;
use App\Models\Environment;
use App\Models\Project;
use App\Models\Server;
use App\Models\Team;
use App\Services\SharedVariableCanvas;
use Illuminate\Contracts\View\View;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Canvas of the shared variables of one scope and the resources that use them.
 * The page is a shell: resources/js/environment-canvas.js renders it from
 * Api\SharedVariableCanvasController at /canvas/api with the browser session.
 */
class Canvas extends Component
{
    use AuthorizesRequests;
    use RemembersCanvasView;

    #[Locked]
    public string $scope = 'team';

    #[Locked]
    public string $endpoint = '';

    #[Locked]
    public string $listUrl = '';

    /** The team that owned the page at mount; later requests use it, not the session team. */
    #[Locked]
    public int $teamId;

    #[Locked]
    public ?string $projectUuid = null;

    #[Locked]
    public ?string $environmentUuid = null;

    #[Locked]
    public ?string $serverUuid = null;

    public function mount(SharedVariableCanvas $canvas, ?string $project_uuid = null, ?string $environment_uuid = null, ?string $server_uuid = null): void
    {
        $this->teamId = currentTeam()->id;
        $this->projectUuid = $project_uuid;
        $this->environmentUuid = $environment_uuid;
        $this->serverUuid = $server_uuid;
        $this->scope = match (true) {
            $server_uuid !== null => 'server',
            $environment_uuid !== null => 'environment',
            $project_uuid !== null => 'project',
            default => 'team',
        };
        $owner = $this->owner();
        $this->authorize('view', $owner);

        $this->endpoint = $this->scope === 'team'
            ? '/shared-variables/team/canvas'
            : "/shared-variables/{$this->scope}/{$owner->uuid}/canvas";
        $this->listUrl = $canvas->pageLink($this->scope, $owner);
        $this->rememberView("{$this->scope}_variables", 'canvas');
    }

    /**
     * Saves a variable from the "Add variable" modal (the Add component dispatches saveKey),
     * then reloads the canvas so it shows the new card.
     *
     * @param  array{key: string, value: string, is_multiline: bool, is_literal: bool, comment?: ?string}  $data
     */
    #[On('saveKey')]
    public function saveKey(array $data)
    {
        try {
            $owner = $this->owner();
            $this->authorize('update', $owner);

            if ($this->scope === 'server' && in_array($data['key'], ['COOLIFY_SERVER_UUID', 'COOLIFY_SERVER_NAME'])) {
                throw new \Exception('Cannot create predefined variable.');
            }
            if ($owner->environment_variables()->where('key', $data['key'])->exists()) {
                throw new \Exception('Variable already exists.');
            }
            $owner->environment_variables()->create([
                'key' => $data['key'],
                'value' => $data['value'],
                'is_multiline' => $data['is_multiline'],
                'is_literal' => $data['is_literal'],
                'comment' => $data['comment'] ?? null,
                'type' => $this->scope,
                'team_id' => $this->ownerTeamId($owner),
            ]);

            return $this->redirect($this->canvasUrl(), navigate: true);
        } catch (\Throwable $e) {
            return handleError($e, $this);
        }
    }

    /**
     * The team, project, environment, or server whose variables the canvas shows, scoped to the page's team.
     */
    private function owner(): Team|Project|Environment|Server
    {
        $teamId = $this->teamId;

        return match ($this->scope) {
            'server' => Server::query()->where('team_id', $teamId)->where('uuid', $this->serverUuid)->firstOrFail(),
            'environment' => Environment::query()->where('uuid', $this->environmentUuid)
                ->whereHas('project', fn ($query) => $query->where('team_id', $teamId)->where('uuid', $this->projectUuid))->with('project')->firstOrFail(),
            'project' => Project::query()->where('team_id', $teamId)->where('uuid', $this->projectUuid)->firstOrFail(),
            default => Team::query()->findOrFail($teamId),
        };
    }

    private function ownerTeamId(Team|Project|Environment|Server $owner): int
    {
        return match (true) {
            $owner instanceof Team => $owner->id,
            $owner instanceof Environment => $owner->project->team_id,
            default => $owner->team_id,
        };
    }

    private function canvasUrl(): string
    {
        return match ($this->scope) {
            'server' => route('shared-variables.server.canvas', ['server_uuid' => $this->serverUuid]),
            'environment' => route('shared-variables.environment.canvas', ['project_uuid' => $this->projectUuid, 'environment_uuid' => $this->environmentUuid]),
            'project' => route('shared-variables.project.canvas', ['project_uuid' => $this->projectUuid]),
            default => route('shared-variables.team.canvas'),
        };
    }

    public function render(): View
    {
        return view('livewire.shared-variables.canvas', [
            'canAddVariables' => auth()->user()->can('update', $this->owner()),
        ])->layout('layouts.app', ['fullBleed' => true]);
    }
}
