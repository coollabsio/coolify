<?php

namespace App\Services\DeployTriggers\Git;

use App\Models\Application;
use App\Models\Server;
use App\Services\DeployTriggers\AffectedRunner;
use App\Services\DeployTriggers\CompiledTriggerSet;
use App\Services\DeployTriggers\Contract\ApplicationDecision;
use App\Services\DeployTriggers\Contract\EvaluateApplicationInput;
use App\Services\DeployTriggers\Contract\EvaluateRequest;
use App\Services\DeployTriggers\Contract\EvaluateResponse;
use App\Services\DeployTriggers\GitAccess;
use App\Services\DeployTriggers\Resolution\TriggerSetBuilder;
use App\Services\DeployTriggers\Resolution\WidenException;
use App\Services\DeployTriggers\TriggerSetDecider;
use Closure;

/**
 * Evaluates a push with stock git in a blobless mirror on a server of the team, and resolves trigger sets in PHP.
 */
final class GitEvaluator implements AffectedRunner
{
    public const FETCH_TIMEOUT_SECONDS = 240;

    public const READ_TIMEOUT_SECONDS = 120;

    public const MAX_TREE_ENTRIES = 150000;

    public const MAX_GREP_FILES = 20000;

    /** @var Closure(Server): MirrorGit */
    private readonly Closure $mirrorFor;

    /**
     * @param  (Closure(Server): MirrorGit)|null  $mirrorFor
     */
    public function __construct(?Closure $mirrorFor = null)
    {
        $this->mirrorFor = $mirrorFor ?? fn (Server $server): MirrorGit => new SshMirrorGit($server);
    }

    public function supports(Server $server): bool
    {
        return true;
    }

    public function evaluate(Server $server, EvaluateRequest $request, GitAccess $access): EvaluateResponse
    {
        $session = new MirrorSession(($this->mirrorFor)($server), $access, $request, $this->redactor($access));
        $limits = $request->limits ?? EvaluateRequest::defaultLimits();

        $bases = [];
        $mergeBases = [];
        foreach ($request->applications as $input) {
            if ($input->baseCommit !== null) {
                $bases[$input->baseCommit] = true;
            } elseif ($input->mergeBaseRef !== null) {
                $mergeBases['merge:'.$input->mergeBaseRef] = $input->mergeBaseRef;
            }
        }
        ['after' => $after, 'diffs' => $diffs] = $session->fetchAndDiff(array_keys($bases), $mergeBases, $limits['max_mirror_bytes']);

        $decisions = [];
        $pending = [];
        foreach ($request->applications as $input) {
            $key = $input->baseCommit ?? ($input->mergeBaseRef !== null ? 'merge:'.$input->mergeBaseRef : null);
            $diff = $key === null ? null : ($diffs[$key] ?? null);
            $result = $this->decide($input, $diff, $session, $after, $limits);
            if ($result instanceof ApplicationDecision) {
                $decisions[$input->id] = $result;
            } else {
                $pending[$input->id] = $result;
            }
        }

        return new EvaluateResponse($after, $this->checkReferences($decisions, $pending, $session, $after));
    }

    /**
     * Searches the packages of the applications for references to workspace packages they do not declare, in one run:
     * for decisions that wait on it, and for sets that a deployment or a new resolution left unchecked.
     *
     * @param  array<string, ApplicationDecision>  $decisions
     * @param  array<string, array{set: array<string, mixed>, files: list<string>, base: ?string}>  $pending
     * @return array<string, ApplicationDecision>
     */
    private function checkReferences(array $decisions, array $pending, MirrorSession $session, string $after): array
    {
        $sets = array_map(fn (array $item): array => $item['set'], $pending);
        foreach ($decisions as $id => $decision) {
            $set = $decision->triggerSet;
            if ($set !== null && $decision->deploys() && ($set['excluded'] ?? []) !== [] && ($set['packages'] ?? []) !== []) {
                $sets[$id] = $set;
            }
        }
        if ($sets === []) {
            return $decisions;
        }

        $referenced = $session->references($after, array_map(fn (array $set): array => [
            'directories' => array_values($set['packages']),
            'packages' => $set['excluded'],
            'needles' => is_array($set['reference_needles'] ?? null) ? $set['reference_needles'] : [],
        ], $sets), self::MAX_GREP_FILES);

        foreach ($sets as $id => $set) {
            $checked = $referenced === null ? null : [...TriggerSetDecider::withReferences($set, $referenced[$id] ?? []), 'references_checked' => true];
            if (isset($pending[$id])) {
                ['files' => $files, 'base' => $base] = $pending[$id];
                $decisions[$id] = $checked === null
                    ? new ApplicationDecision($id, 'deploy', 'references_unchecked', 'The push changes another package, and the application has too many files to check for references to it, so it deploys.', $base, triggerSet: $set)
                    : TriggerSetDecider::decide($id, $checked, $files, $base)['decision'];

                continue;
            }

            $decision = $decisions[$id];
            $decisions[$id] = new ApplicationDecision($id, $decision->decision, $decision->reasonCode, $decision->reason, $decision->base, $decision->matches, $decision->lockfile,
                $checked ?? [...$set, 'references_checked' => false]);
        }

        return $decisions;
    }

    /**
     * @param  array{base: ?string, files: ?list<string>, symlinks: list<string>}|null  $diff
     * @param  array<string, int>  $limits
     * @return ApplicationDecision|array{set: array<string, mixed>, files: list<string>, base: ?string} a set that waits on the reference check
     */
    private function decide(EvaluateApplicationInput $input, ?array $diff, MirrorSession $session, string $after, array $limits): ApplicationDecision|array
    {
        $id = $input->id;
        $base = $diff['base'] ?? $input->baseCommit;
        $files = $diff['files'] ?? null;
        if ($files === null) {
            $short = $base === null ? 'the base' : substr($base, 0, 7);

            return ApplicationDecision::deployBecause($id, 'base_unreachable', "Coolify could not compare the push with {$short}, so it deploys.", $base);
        }
        if ($files === []) {
            // Evaluate now compares the deployed commit with itself; it still wants the resolved set.
            $set = null;
            if ($input->mode === 'smart') {
                try {
                    $set = $this->triggerSet($input, [], [], $session, $after);
                } catch (WidenException) {
                }
            }

            return new ApplicationDecision($id, 'deploy', 'empty_diff', 'The push changes no file, so it deploys.', $base, triggerSet: $set);
        }
        if (count($files) > $limits['max_changed_files']) {
            return ApplicationDecision::deployBecause($id, 'too_many_files', 'The push changes too many files to check, so it deploys.', $base);
        }
        $config = array_values(array_intersect($files, $input->managedConfigPaths));
        if ($config !== []) {
            return new ApplicationDecision($id, 'deploy', 'config_changed', "{$config[0]} changed.", $base, [['file' => $config[0], 'rule' => 'coolify_json']]);
        }

        if ($input->mode === 'watch_paths') {
            $matched = Application::matchPaths(collect($files), collect(explode("\n", (string) $input->watchPaths)));
            $count = count($files);

            return $matched->isEmpty()
                ? new ApplicationDecision($id, 'skip', 'watch_paths_no_match', "None of the {$count} changed files match the watch paths.", $base)
                : new ApplicationDecision($id, 'deploy', 'watch_paths_matched', "{$matched->first()} matches the watch paths.", $base, [['file' => $matched->first(), 'rule' => 'watch_paths']]);
        }

        try {
            $set = $this->triggerSet($input, [...$files], $diff['symlinks'] ?? [], $session, $after);
            $decision = TriggerSetDecider::decide($id, $set, $files, $base)['decision'];

            return $decision ?? ['set' => $set, 'files' => $files, 'base' => $base];
        } catch (WidenException $exception) {
            return ApplicationDecision::deployBecause($id, 'evaluation_limited', $exception->getMessage(), $base);
        }
    }

    /**
     * The stored set when no input of resolution changed, else a set resolved at the pushed commit.
     *
     * @param  list<string>  $files
     * @param  list<string>  $symlinks  changed paths that are or were symlinks
     * @return array<string, mixed>
     */
    private function triggerSet(EvaluateApplicationInput $input, array $files, array $symlinks, MirrorSession $session, string $after): array
    {
        $stored = $input->storedTriggerSet;
        if ($stored !== null && $symlinks === []) {
            $inputs = CompiledTriggerSet::fromArray($stored['input_patterns']);
            if (! collect($files)->contains(fn (string $file): bool => $inputs->matches($file))) {
                return $stored;
            }
        }

        return (new TriggerSetBuilder)->build($input, $session->snapshot($after, self::MAX_TREE_ENTRIES))->toArray();
    }

    /**
     * @return Closure(string): string
     */
    private function redactor(GitAccess $access): Closure
    {
        $secrets = [];
        foreach ($access->config as $value) {
            if (preg_match('~://[^:/@]+:([^@]+)@~', $value, $match) === 1) {
                array_push($secrets, $match[1], rawurldecode($match[1]));
            }
            if (preg_match('~Authorization: \S+ (\S+)~', $value, $match) === 1) {
                $secrets[] = $match[1];
            }
        }

        return fn (string $message): string => mb_substr(str_replace(array_filter($secrets), '***', $message), 0, 500);
    }
}
