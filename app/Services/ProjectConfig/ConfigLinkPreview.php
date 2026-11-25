<?php

namespace App\Services\ProjectConfig;

use App\Models\EnvironmentConfigLink;
use App\Models\User;
use RuntimeException;
use Throwable;

/**
 * Reads coolify.json for a link that is not saved yet and plans it. Nothing is stored.
 */
class ConfigLinkPreview
{
    public function __construct(
        private readonly RepositoryFileReader $reader,
        private readonly ConfigRevisions $revisions,
        private readonly ConfigPlanner $planner,
    ) {}

    /**
     * The link needs its environment, read server, and source relations.
     *
     * @return array{plan: ?array, error: ?string}
     */
    public function plan(EnvironmentConfigLink $link, ?User $user): array
    {
        try {
            $file = $this->reader->read($link);
            if ($file->missing) {
                return ['plan' => null, 'error' => "{$link->file_path} was not found on branch {$link->branch}."];
            }
            $resolved = $this->revisions->resolve($link, (string) $file->contents);
            $plan = $this->planner->plan($resolved, ApplyContext::for($link, $user))->toArray();

            return ['plan' => $plan + ['commit' => $file->commitSha], 'error' => null];
        } catch (ConfigException $e) {
            return ['plan' => null, 'error' => $e->getMessage()];
        } catch (RuntimeException $e) {
            return ['plan' => null, 'error' => $e->getMessage()];
        } catch (Throwable) {
            return ['plan' => null, 'error' => 'Coolify could not read coolify.json.'];
        }
    }
}
