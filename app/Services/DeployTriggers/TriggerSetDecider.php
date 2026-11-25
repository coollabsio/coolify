<?php

namespace App\Services\DeployTriggers;

use App\Services\DeployTriggers\Contract\ApplicationDecision;
use App\Services\DeployTriggers\Resolution\RepoPath;

/**
 * Decides a push from a resolved trigger set and the changed files.
 */
final class TriggerSetDecider
{
    /**
     * @param  array<string, mixed>  $set  a trigger set without bookkeeping
     * @param  list<string>  $files
     * @return array{decision: ?ApplicationDecision, references: array<string, string>} references: excluded packages, name => directory,
     *                                                                                  that the push changes and that only the workspace limit leaves out
     */
    public static function decide(string $id, array $set, array $files, ?string $base): array
    {
        $compiled = CompiledTriggerSet::fromArray($set['compiled']);
        $match = $compiled->firstMatch($files);
        if ($match !== null) {
            return ['decision' => new ApplicationDecision($id, 'deploy', 'matched', "{$match} changed.", $base, [[
                'file' => $match,
                'rule' => 'trigger_set',
                'via' => self::via($set, $match),
            ]], triggerSet: $set), 'references' => []];
        }

        $references = ($set['references_checked'] ?? false) === true ? [] : self::touchedExcludedPackages($set, $files);
        if ($references !== []) {
            return ['decision' => null, 'references' => $references];
        }

        return ['decision' => self::skip($id, $set, $files, $base), 'references' => []];
    }

    /**
     * @param  array<string, mixed>  $set
     * @param  list<string>  $files
     */
    public static function skip(string $id, array $set, array $files, ?string $base): ApplicationDecision
    {
        $count = count($files);

        return new ApplicationDecision($id, 'skip', 'no_match', "None of the {$count} changed files affect this application.", $base, triggerSet: $set);
    }

    /**
     * The set with the referenced packages moved into the closure.
     *
     * @param  array<string, mixed>  $set
     * @param  array<string, string>  $referenced  name => directory
     * @return array<string, mixed>
     */
    public static function withReferences(array $set, array $referenced): array
    {
        foreach ($set['compiled']['all_of'] as $index => $ruleset) {
            if (($ruleset['limit'] ?? null) === 'workspace') {
                foreach ($referenced as $directory) {
                    $set['compiled']['all_of'][$index]['rules'][] = ['action' => 'include', 'kind' => 'dir', 'path' => $directory];
                }
            }
        }
        $set['packages'] = [...($set['packages'] ?? []), ...$referenced];
        $set['excluded'] = array_diff_key($set['excluded'] ?? [], $referenced);
        foreach ($referenced as $directory) {
            $set['display'][] = "workspace: {$directory}/**";
        }

        return $set;
    }

    /**
     * @param  array<string, mixed>  $set
     * @return list<string>
     */
    private static function via(array $set, string $file): array
    {
        $anchor = $set['anchor']['package'] ?? null;
        $owner = null;
        $length = -1;
        foreach (is_array($set['packages'] ?? null) ? $set['packages'] : [] as $name => $directory) {
            if (RepoPath::isWithin($file, $directory) && strlen($directory) > $length) {
                $owner = $name;
                $length = strlen($directory);
            }
        }

        return array_values(array_unique(array_filter([$anchor, $owner])));
    }

    /**
     * @param  array<string, mixed>  $set
     * @param  list<string>  $files
     * @return array<string, string>
     */
    private static function touchedExcludedPackages(array $set, array $files): array
    {
        $excluded = is_array($set['excluded'] ?? null) ? $set['excluded'] : [];
        if ($excluded === []) {
            return [];
        }

        $others = CompiledTriggerSet::fromArray([
            ...$set['compiled'],
            'all_of' => array_values(array_filter($set['compiled']['all_of'], fn (array $ruleset): bool => ($ruleset['limit'] ?? null) !== 'workspace')),
        ]);

        return array_filter($excluded, fn (string $directory): bool => collect($files)
            ->contains(fn (string $file): bool => RepoPath::isWithin($file, $directory) && $others->matches($file)));
    }
}
