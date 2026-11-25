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
        $this->script = new MirrorScript($git, $access, $request->mirrorKey);
    }

    /**
     * @param  list<string>  $bases
     * @param  array<string, string>  $mergeBases  key => ref
     * @return array{after: string, diffs: array<string, array{base: ?string, files: ?list<string>, symlinks: list<string>}>}
     */
    public function fetchAndDiff(array $bases, array $mergeBases, int $maxMirrorBytes): array
    {
        $tooLarge = 'deploy-triggers:mirror-too-large:'.$this->request->mirrorKey;
        if (Cache::has($tooLarge)) {
            throw new AffectedRunnerException('The repository is larger than the limit of push evaluation, so pushes deploy.');
        }
        try {
            $output = $this->run($this->script->fetchAndDiff($this->request->after, $this->request->fetchRefs, $bases, $mergeBases, $maxMirrorBytes), GitEvaluator::FETCH_TIMEOUT_SECONDS);
        } catch (AffectedRunnerException $exception) {
            if (str_contains($exception->getMessage(), 'larger than the limit')) {
                Cache::put($tooLarge, true, now()->addDay());
            }

            throw $exception;
        }
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
        $entries = MirrorOutput::tree(MirrorOutput::payload($output->first('TREE')[0] ?? ''));
        if (count($entries) > $maxEntries) {
            throw new WidenException('The repository has too many files to resolve which of them affect the application, so it deploys.');
        }

        $blobs = MirrorOutput::blobs(MirrorOutput::payload($output->first('BLOBS')[0] ?? ''));
        $loader = function (array $oids) use (&$blobs): array {
            $missing = array_values(array_diff($oids, array_keys($blobs)));
            if ($missing !== []) {
                $loaded = $this->run($this->script->blobs($missing), GitEvaluator::READ_TIMEOUT_SECONDS);
                $blobs += MirrorOutput::blobs(MirrorOutput::payload($loaded->first('BLOBS')[0] ?? ''));
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
        $payload = $output->first('GREP')[0] ?? '-';
        if ($payload === '-') {
            return null;
        }

        $found = [];
        foreach (explode("\n", MirrorOutput::payload($payload)) as $line) {
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
            $output = $this->git->run($script, $timeout);
        } catch (AffectedRunnerException $exception) {
            throw new AffectedRunnerException(($this->redact)($exception->getMessage()));
        }

        return MirrorOutput::parse($output, $this->redact);
    }
}
