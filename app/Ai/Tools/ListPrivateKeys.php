<?php

namespace App\Ai\Tools;

use App\Ai\Concerns\AuthorizesToolAction;
use App\Models\PrivateKey;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;

class ListPrivateKeys implements Tool
{
    use AuthorizesToolAction;

    public function description(): string
    {
        return 'List SSH private keys in the current team as "uuid — name (git)" lines. Key material is never shown. '
            .'Use a git-related key uuid as private_key_uuid when creating a private-deploy-key application.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'search' => $schema->string()->description('Filter by name or description.'),
        ];
    }

    public function handle(Request $request): string
    {
        $args = $request->validate(['search' => 'nullable|string']);
        $search = isset($args['search']) ? str($args['search'])->lower()->value() : null;

        $keys = PrivateKey::query()->where('team_id', $this->actingTeamId())->orderBy('name')->get()
            ->filter(fn (PrivateKey $k) => $search === null || str_contains(str("{$k->name} {$k->description}")->lower()->value(), $search));

        if ($keys->isEmpty()) {
            return 'No private keys found in this team.';
        }

        return $keys->map(fn (PrivateKey $k) => "{$k->uuid} — {$k->name}".($k->is_git_related ? ' (git)' : '').(filled($k->description) ? ": {$k->description}" : ''))
            ->implode("\n");
    }
}
