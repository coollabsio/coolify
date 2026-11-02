<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Application;
use App\Models\Environment;
use App\Models\EnvironmentVariable;
use App\Models\Project;
use App\Models\Server;
use App\Models\Service;
use App\Models\SharedEnvironmentVariable;
use App\Models\Team;
use App\Services\SharedVariableCanvas;
use App\Traits\EnvironmentVariableProtection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * Shared variables canvas: shows which resources use the shared variables of a scope, and adds
 * a shared variable to a resource (a resource variable that holds {{scope.KEY}}) or removes it.
 * Called by resources/js/environment-canvas.js through the /canvas/api session bridge.
 */
class SharedVariableCanvasController extends Controller
{
    use EnvironmentVariableProtection;

    public function __construct(private SharedVariableCanvas $canvas) {}

    public function show(Request $request): JsonResponse
    {
        $scope = $this->scope($request);
        $owner = $this->findOwner($scope, $request->route('uuid'));
        if ($owner instanceof JsonResponse) {
            return $owner;
        }
        $this->authorize('view', $owner);

        return response()->json($this->canvas->payload($scope, $owner, $this->canEdit($request, $scope, $owner)));
    }

    public function storeLink(Request $request): JsonResponse
    {
        $scope = $this->scope($request);
        $owner = $this->findOwner($scope, $request->route('uuid'));
        if ($owner instanceof JsonResponse) {
            return $owner;
        }
        $this->authorize('view', $owner);

        $return = validateIncomingRequest($request);
        if ($return instanceof JsonResponse) {
            return $return;
        }
        $validator = Validator::make($request->all(), [
            'source' => ['required', 'string', 'max:255'],
            'target' => ['required', 'string', 'max:255'],
        ]);
        if ($validator->fails()) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $validator->errors()], 422);
        }

        $variable = $this->findVariable($owner, $request->input('source'));
        $resource = $this->canvas->resources($scope, $owner)->get($request->input('target'));
        $errors = [];
        if (! $variable) {
            $errors['source'] = ['The source must be a shared variable of this scope.'];
        }
        if (! $resource) {
            $errors['target'] = ['The target must be a resource that can use this shared variable.'];
        }
        if ($errors !== []) {
            return response()->json(['message' => 'Validation failed.', 'errors' => $errors], 422);
        }
        $this->authorize('manageEnvironment', $resource);

        $existing = $this->canvas->resourceVariables(collect([$resource]))->firstWhere('key', $variable->key);
        if ($existing) {
            $message = in_array($variable->key, $this->canvas->referencedKeys($scope, $existing->value), true)
                ? "{$resource->name} already uses {$variable->key}."
                : "{$resource->name} already has a variable named {$variable->key}. Rename or remove it first.";

            return response()->json(['message' => $message, 'errors' => ['target' => [$message]]], 422);
        }

        $environmentVariable = new EnvironmentVariable;
        $environmentVariable->key = $variable->key;
        $environmentVariable->value = $this->canvas->reference($scope, $variable->key);
        $environmentVariable->is_multiline = (bool) $variable->is_multiline;
        $environmentVariable->is_literal = (bool) $variable->is_literal;
        $environmentVariable->resourceable_id = $resource->getKey();
        $environmentVariable->resourceable_type = $resource->getMorphClass();
        $environmentVariable->save();

        $link = collect($this->canvas->links($scope, collect([$variable]), collect([$resource->uuid => $resource])))
            ->firstWhere('source', $this->canvas->variableNodeId($variable));

        return response()->json($link, 201);
    }

    public function destroyLink(Request $request): JsonResponse
    {
        $scope = $this->scope($request);
        $owner = $this->findOwner($scope, $request->route('uuid'));
        if ($owner instanceof JsonResponse) {
            return $owner;
        }
        $this->authorize('view', $owner);

        [$source, $target] = array_pad(explode('|', (string) $request->route('link_id'), 2), 2, '');
        $resource = $this->canvas->resources($scope, $owner)->get($target);
        $variable = $this->findVariable($owner, $source);
        $key = $variable?->key ?? (str_starts_with($source, 'missing:') ? substr($source, strlen('missing:')) : null);
        if (! $resource || blank($key)) {
            return response()->json(['message' => 'Link not found.'], 404);
        }
        $this->authorize('manageEnvironment', $resource);

        // Preview copies of application variables are removed together with the variable.
        $references = EnvironmentVariable::query()
            ->where('resourceable_type', $resource->getMorphClass())
            ->where('resourceable_id', $resource->getKey())
            ->get()
            ->filter(fn (EnvironmentVariable $environmentVariable) => in_array($key, $this->canvas->referencedKeys($scope, $environmentVariable->value), true));
        if ($references->where('is_preview', false)->isEmpty()) {
            return response()->json(['message' => 'Link not found.'], 404);
        }
        $embedded = $references->reject(fn (EnvironmentVariable $environmentVariable) => $this->canvas->isExactReference($scope, $key, $environmentVariable->value));
        if ($embedded->isNotEmpty()) {
            $message = "{$embedded->pluck('key')->unique()->join(', ')} on {$resource->name} uses {$this->canvas->reference($scope, $key)} inside a longer value. Edit it on the resource.";

            return response()->json(['message' => $message], 422);
        }
        if ($this->usesDockerCompose($resource)) {
            foreach ($references->pluck('key')->unique() as $environmentKey) {
                [$isUsed, $reason] = $this->isEnvironmentVariableUsedInDockerCompose($environmentKey, $resource->docker_compose);
                if ($isUsed) {
                    return response()->json(['message' => "Cannot remove {$environmentKey} from {$resource->name}. {$reason}"], 422);
                }
            }
        }

        DB::transaction(fn () => $references->each->delete());

        return response()->json(['message' => 'Link removed.']);
    }

    /**
     * The team routes have no {scope} segment.
     */
    private function scope(Request $request): string
    {
        return $request->route('scope') ?? 'team';
    }

    private function findOwner(string $scope, ?string $uuid): Model|JsonResponse
    {
        $teamId = getTeamIdFromToken();
        if (is_null($teamId)) {
            return invalidTokenResponse();
        }
        $owner = match ($scope) {
            'team' => Team::query()->find($teamId),
            'project' => Project::query()->where('team_id', $teamId)->where('uuid', $uuid)->first(),
            'environment' => Environment::query()->where('uuid', $uuid)->whereHas('project', fn ($query) => $query->where('team_id', $teamId))->with('project')->first(),
            'server' => Server::query()->where('team_id', $teamId)->where('uuid', $uuid)->first(),
            default => null,
        };

        return $owner ?? response()->json(['message' => str($scope)->headline().' not found.'], 404);
    }

    private function findVariable(Model $owner, ?string $nodeId): ?SharedEnvironmentVariable
    {
        if (! str_starts_with((string) $nodeId, 'variable:')) {
            return null;
        }

        return $owner->environment_variables()->whereKey((int) substr($nodeId, strlen('variable:')))->first();
    }

    private function canEdit(Request $request, string $scope, Model $owner): bool
    {
        $user = $request->user();
        $token = $user->currentAccessToken();

        return $user->isAdminOfTeam($this->canvas->teamId($scope, $owner))
            && ($token->can('root') || $token->can('write'));
    }

    private function usesDockerCompose(Model $resource): bool
    {
        return $resource instanceof Service
            || ($resource instanceof Application && $resource->build_pack === 'dockercompose');
    }
}
