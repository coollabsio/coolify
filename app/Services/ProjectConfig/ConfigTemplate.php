<?php

namespace App\Services\ProjectConfig;

use App\Models\EnvironmentConfigLink;
use App\Models\GiteaApp;
use App\Models\GithubApp;
use App\Models\GitlabApp;
use App\Models\PrivateKey;
use App\Models\User;
use App\Policies\ResourceCreatePolicy;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use PDOException;
use RuntimeException;
use Throwable;

/**
 * Uses coolify.json of a repository one time, as the default settings of new resources.
 * The resources are normal resources after that: the file does not manage them.
 *
 * The link is not saved. It needs its environment, read server, and source relations, like a link preview.
 */
class ConfigTemplate
{
    public function __construct(
        private readonly RepositoryFileReader $reader,
        private readonly ConfigParser $parser,
        private readonly ConfigResolver $resolver,
        private readonly ConfigPlanner $planner,
        private readonly ConfigApplier $applier,
    ) {}

    /**
     * @return array{plan: ?array, error: ?string, errors: list<array{path: string, message: string}>}
     */
    public function plan(EnvironmentConfigLink $link, User $user): array
    {
        try {
            [$plan, $commit] = $this->planAt($link, $user, null);

            return ['plan' => $plan->toArray() + ['commit' => $commit], 'error' => null, 'errors' => []];
        } catch (ConfigException $e) {
            return ['plan' => null, 'error' => $e->getMessage(), 'errors' => array_map(fn (ConfigError $error) => $error->toArray(), $e->errors())];
        } catch (PDOException $e) {
            // A database error is a RuntimeException too, and its message has the SQL.
            report($e);

            return ['plan' => null, 'error' => 'Coolify could not read coolify.json.', 'errors' => []];
        } catch (RuntimeException|AuthorizationException $e) {
            return ['plan' => null, 'error' => $e->getMessage(), 'errors' => []];
        } catch (Throwable) {
            return ['plan' => null, 'error' => 'Coolify could not read coolify.json.', 'errors' => []];
        }
    }

    /**
     * Creates the resources from the file at the commit of the plan that the user saw.
     *
     * @return list<Model>
     *
     * @throws AuthorizationException
     * @throws RuntimeException when the file has errors
     */
    public function create(EnvironmentConfigLink $link, User $user, ?string $commit): array
    {
        [$plan, , $resolved, $ctx] = $this->planAt($link, $user, $commit);
        if ($plan->hasErrors()) {
            throw new RuntimeException('The file has errors. Scan again to see them.');
        }

        return $this->applier->createFromTemplate($resolved, $ctx);
    }

    /**
     * @return array{0: ConfigPlan, 1: ?string, 2: ResolvedEnvironment, 3: ApplyContext}
     */
    private function planAt(EnvironmentConfigLink $link, User $user, ?string $commit): array
    {
        $environment = $link->environment;
        if (! app(ResourceCreatePolicy::class)->createAny($user, $environment)) {
            throw new AuthorizationException('You cannot create resources in this environment.');
        }
        if ($environment->configLink()->exists()) {
            throw new RuntimeException('This environment follows coolify.json. Add the resources to its file instead.');
        }
        $file = $this->reader->read($link, $commit);
        if ($file->missing) {
            throw new RuntimeException("{$link->file_path} was not found on branch {$link->branch}.");
        }
        $resolved = $this->resolver
            ->forTemplate($this->parser->parse((string) $file->contents, template: true), (string) $link->branch, $environment->name)
            ->withDefaultPlacement($link->defaultPlacement())
            ->asTemplate($this->source($link));
        $ctx = ApplyContext::forTemplate($link, $user);

        return [$this->planner->plan($resolved, $ctx), $file->commitSha, $resolved, $ctx];
    }

    /**
     * The repository and git source that the user adds, in the shape of `source` in the file.
     *
     * @return array{repository: string, branch: string, github_app?: string, gitlab_app?: string, gitea_app?: string, deploy_key?: string}
     */
    private function source(EnvironmentConfigLink $link): array
    {
        $source = ['repository' => (string) $link->repository, 'branch' => (string) $link->branch];
        $app = $link->source;
        if ($link->private_key_id !== null) {
            $source['deploy_key'] = (string) PrivateKey::query()->whereKey($link->private_key_id)->value('name');
        } elseif ($app instanceof GitlabApp) {
            $source['gitlab_app'] = $app->name;
        } elseif ($app instanceof GiteaApp) {
            $source['gitea_app'] = $app->name;
        } elseif ($app instanceof GithubApp && (int) $app->id !== 0) {
            $source['github_app'] = $app->name;
        }

        return $source;
    }
}
