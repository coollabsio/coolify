<?php

namespace App\Services\DeployTriggers\Git;

use App\Services\DeployTriggers\AffectedRunnerException;
use App\Services\DeployTriggers\Contract\AffectedContractException;
use App\Services\DeployTriggers\Contract\EvaluateRequest;
use App\Services\DeployTriggers\Contract\Sha;
use App\Services\DeployTriggers\GitAccess;
use App\Services\DeployTriggers\Resolution\RepoPath;
use App\Services\DeployTriggers\Resolution\RepositorySnapshot;
use App\Services\DeployTriggers\Resolution\TriggerSetBuilder;
use App\Services\DeployTriggers\Resolution\WidenException;
use Closure;
use Illuminate\Support\Facades\Cache;

/**
 * The mirror reads of one push evaluation. Each read is one short-lived container run.
 */
final class MirrorSession
{
    private readonly MirrorScript $script;

    private ?RepositorySnapshot $snapshot = null;

    /**
     * @param  Closure(string): string  $redact
     */
    public function __construct(
        private readonly MirrorGit $git,
        GitAccess $access,
        private readonly EvaluateRequest $request,
        private readonly Closure $redact,
    ) {
        $this->script = new MirrorScript($git, $access, $request->mirrorKey, ($request->limits ?? EvaluateRequest::defaultLimits())['max_mirror_bytes']);
    }

    /**
     * @param  list<string>  $bases
     * @param  array<string, string>  $mergeBases  key => ref
     * @return array{after: string, diffs: array<string, array{base: ?string, files: ?list<string>, symlinks: list<string>}>}
     */
    public function fetchAndDiff(array $bases, array $mergeBases): array
    {
        if (Cache::has($this->tooLargeKey())) {
            throw new AffectedRunnerException('The repository is larger than the limit of push evaluation, so pushes deploy.');
        }
        $output = $this->run($this->script->fetchAndDiff($this->request->after, $this->request->fetchRefs, $bases, $mergeBases), GitEvaluator::FETCH_TIMEOUT_SECONDS);
        $after = $output->first('AFTER')[0] ?? null;
        if (! Sha::isValid($after) || ($this->request->after !== null && $after !== $this->request->after)) {
            throw new AffectedContractException('The git mirror reported an invalid commit.');
        }

        $diffs = [];
        foreach ($output->all('DIFF') as [$key, $base, $payload]) {
            $diff = $payload === '-' ? null : MirrorOutput::rawDiff(MirrorOutput::payload($payload));
            $diffs[$key] = [
                'base' => Sha::isValid($base) ? $base : null,
                'files' => $diff['files'] ?? null,
                'symlinks' => $diff['symlinks'] ?? [],
            ];
        }

        return ['after' => $after, 'diffs' => $diffs];
    }

    public function snapshot(string $after, int $maxEntries): RepositorySnapshot
    {
        if ($this->snapshot !== null) {
            return $this->snapshot;
        }

        $wanted = [];
        foreach ($this->request->applications as $input) {
            array_push($wanted, ...TriggerSetBuilder::wantedPaths($input));
        }
        $output = $this->run($this->script->tree($after, array_values(array_unique($wanted))), GitEvaluator::READ_TIMEOUT_SECONDS);
        $entries = MirrorOutput::tree($output->requiredPayload('TREE'));
        if (count($entries) > $maxEntries) {
            throw new WidenException('The repository has too many files to resolve which of them affect the application, so it deploys.');
        }

        $blobs = MirrorOutput::blobs($output->requiredPayload('BLOBS'));
        $loader = function (array $oids) use (&$blobs): array {
            $missing = array_values(array_diff($oids, array_keys($blobs)));
            if ($missing !== []) {
                $loaded = $this->run($this->script->blobs($missing), GitEvaluator::READ_TIMEOUT_SECONDS);
                $blobs += MirrorOutput::blobs($loaded->requiredPayload('BLOBS'));
                if (array_diff($missing, array_keys($blobs)) !== []) {
                    throw new AffectedContractException('The git mirror did not return the contents of a file.');
                }
            }

            return array_intersect_key($blobs, array_flip($oids));
        };

        return $this->snapshot = new RepositorySnapshot($entries, $loader);
    }

    /**
     * For each application, the packages whose name or directory occurs in a text file of its own packages.
     * One search covers every application.
     *
     * @param  array<string, array{directories: list<string>, packages: array<string, string>, needles: array<string, list<string>>}>  $applications
     * @return array<string, array<string, string>>|null null when there are too many files to search
     */
    public function references(string $after, array $applications, int $maxFiles): ?array
    {
        $directories = [];
        $needles = [];
        foreach ($applications as $id => $application) {
            array_push($directories, ...$application['directories']);
            foreach ($application['packages'] as $name => $directory) {
                $applications[$id]['needles'][$name] ??= [$name, $directory];
                array_push($needles, ...$applications[$id]['needles'][$name]);
            }
        }

        $needles = array_values(array_unique($needles));
        $output = $this->run($this->script->grep($after, array_values(array_unique($directories)), $needles, $maxFiles), GitEvaluator::READ_TIMEOUT_SECONDS);
        if (($output->first('GREP')[0] ?? null) === '-') {
            return null;
        }

        $found = [];
        foreach (explode("\n", $output->requiredPayload('GREP')) as $line) {
            $separator = strpos($line, "\0");
            if ($separator !== false && str_starts_with($line, "{$after}:")) {
                $found[substr($line, strlen($after) + 1, $separator - strlen($after) - 1)."\0".substr($line, $separator + 1)] = true;
            }
        }
        $found = array_map(fn (string $key): array => explode("\0", $key, 2), array_keys($found));

        $result = [];
        foreach ($applications as $id => $application) {
            $result[$id] = array_filter($application['packages'], fn (string $directory, string $name): bool => collect($found)->contains(
                fn (array $match): bool => in_array($match[1], $application['needles'][$name], true)
                    && collect($application['directories'])->contains(fn (string $own): bool => RepoPath::isWithin($match[0], $own)),
            ), ARRAY_FILTER_USE_BOTH);
        }

        return $result;
    }

    private function run(string $script, int $timeout): MirrorOutput
    {
        try {
            return MirrorOutput::parse($this->git->run($script, $timeout), $this->redact);
        } catch (AffectedRunnerException $exception) {
            if (str_contains($exception->getMessage(), 'larger than the limit')) {
                Cache::put($this->tooLargeKey(), true, now()->addDay());
            }

            throw new AffectedRunnerException(($this->redact)($exception->getMessage()));
        }
    }

    private function tooLargeKey(): string
    {
        return 'deploy-triggers:mirror-too-large:'.$this->request->mirrorKey;
    }
}
