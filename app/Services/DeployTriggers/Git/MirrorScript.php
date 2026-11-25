<?php

namespace App\Services\DeployTriggers\Git;

use App\Services\DeployTriggers\Contract\EvaluateRequest;
use App\Services\DeployTriggers\GitAccess;

/**
 * Builds the POSIX sh scripts that keep a blobless mirror of a repository and read from it with stock git.
 * Every script prints one `@@TAG fields...` line per result and ends with `@@END`; payloads are gzip + base64.
 */
final class MirrorScript
{
    public const LOCK_WAIT_SECONDS = 90;

    public function __construct(
        private readonly MirrorGit $git,
        private readonly GitAccess $access,
        private readonly string $mirrorKey,
        private readonly int $maxMirrorBytes = EvaluateRequest::DEFAULT_MAX_MIRROR_BYTES,
    ) {}

    /**
     * Fetches the push and the bases into the mirror, then prints the changed files from each base to the push.
     *
     * @param  list<string>  $refs  refs to fetch, in order
     * @param  list<string>  $bases  base commits
     * @param  array<string, string>  $mergeBases  key => ref; the base is the merge base of the push and the ref
     */
    public function fetchAndDiff(?string $after, array $refs, array $bases, array $mergeBases): string
    {
        // The mirror may grow up to its limit; a server without that much free space deploys instead.
        $body = [
            'KB=$(du -sk "$G" | cut -f1); FREE=$(df -Pk "$G" | awk \'NR == 2 { print $4 }\')',
            '[ "$FREE" -ge $(('.intdiv($this->maxMirrorBytes, 1024).' - KB)) ] || fail "The server has less free disk space than the repository mirror may use."',
        ];
        foreach (array_values($refs) as $index => $ref) {
            $body[] = 'fetch_quiet '.self::arg("+{$ref}:refs/coolify/ref/{$index}").' || fail "git fetch failed"';
        }
        if ($after === null) {
            $body[] = 'AFTER=$(gitc rev-parse --verify -q refs/coolify/ref/0^{commit}) || fail "The pushed ref was not found."';
        } else {
            $body[] = 'AFTER='.self::arg($after);
            $body[] = 'have_commit "$AFTER" || fail "The pushed commit was not found."';
        }
        $body[] = 'printf "@@AFTER %s\n" "$AFTER"';
        foreach ($bases as $base) {
            $body[] = 'diff_from '.self::arg($base).' '.self::arg($base);
        }
        foreach ($mergeBases as $key => $ref) {
            $index = array_search($ref, $refs, true);
            $local = $index === false ? 'refs/coolify/none' : "refs/coolify/ref/{$index}";
            $body[] = 'MB=$(gitc merge-base "$AFTER" '.self::arg($local).' 2>/dev/null) || MB=-';
            $body[] = 'if [ "$MB" = - ]; then printf "@@DIFF %s - -\n" '.self::arg($key).'; else diff_from '.self::arg($key).' "$MB"; fi';
        }
        $body[] = 'check_size';

        return $this->script($body);
    }

    /**
     * Prints the full tree of a commit and the contents of the files that resolution reads first.
     *
     * @param  list<string>  $wantedPaths  exact paths to load besides manifests, ignore files, and symlinks
     */
    public function tree(string $after, array $wantedPaths): string
    {
        return $this->script([
            'AFTER='.self::arg($after),
            'gitc ls-tree -r -z --full-tree "$AFTER" > "$R/tree" || fail "git ls-tree failed"',
            'printf "@@TREE "; payload "$R/tree"',
            self::file('"$R/wanted"', $wantedPaths),
            'gitc -c core.quotePath=false ls-tree -r --full-tree "$AFTER" | awk -F "\t" \'NR == FNR { w[$0] = 1; next } { split($1, e, " ") } e[1] == "120000" || ($2 in w) || $2 ~ /(^|\/)(package\.json|pnpm-workspace\.yaml|turbo\.json|[^\/]*\.dockerignore|(tsconfig|jsconfig)[^\/]*\.json)$/ { print e[3] }\' "$R/wanted" - | sort -u > "$R/oids"',
            'blobs "$R/oids"',
        ]);
    }

    /**
     * Prints the contents of blobs.
     *
     * @param  list<string>  $oids
     */
    public function blobs(array $oids): string
    {
        return $this->script([
            self::file('"$R/oids"', $oids),
            'blobs "$R/oids"',
        ]);
    }

    /**
     * Prints `<commit>:<path>\0<needle>` lines for each needle that occurs in a text file under the directories,
     * or `@@GREP -` when there are too many files.
     *
     * @param  list<string>  $directories
     * @param  list<string>  $needles
     */
    public function grep(string $after, array $directories, array $needles, int $maxFiles): string
    {
        $pathspecs = implode(' ', array_map(self::arg(...), $directories));

        return $this->script([
            'AFTER='.self::arg($after),
            self::file('"$R/needles"', $needles),
            'gitc ls-tree -r --format="%(objecttype) %(objectname)" "$AFTER" -- '.$pathspecs.' | awk \'$1 == "blob" { print $2 }\' > "$R/oids" || fail "git ls-tree failed"',
            'if [ "$(wc -l < "$R/oids")" -gt '.$maxFiles.' ]; then echo "@@GREP -"; else',
            '  prefetch "$R/oids"',
            '  gitc grep -I -z -o -F -f "$R/needles" "$AFTER" -- '.$pathspecs.' > "$R/grep"; [ $? -le 1 ] || fail "git grep failed"',
            '  printf "@@GREP "; payload "$R/grep"',
            'fi',
        ]);
    }

    /**
     * @param  list<string>  $body
     */
    private function script(array $body): string
    {
        $run = self::arg($this->git->runPath());
        $mirror = self::arg($this->git->mirrorsPath().'/'.$this->mirrorKey);
        $lines = [
            'set -u',
            'set -o pipefail 2>/dev/null || true',
            'umask 077',
            "R={$run}",
            "G={$mirror}.git",
            'mkdir -p "$R" "$(dirname "$G")"',
            'fail() { printf "@@ERROR %s\n" "$(printf "%s: %s" "$1" "$(tail -c 400 "$R/err" 2>/dev/null)" | base64 | tr -d "\n")"; exit 3; }',
            'payload() { gzip -c "$1" | base64 | tr -d "\n"; echo; }',
            ': > "$R/err"',
        ];

        foreach ($this->access->files as $name => $content) {
            if (preg_match('/\A[a-z_]+\z/', $name) === 1) {
                $lines[] = 'printf %s '.self::arg(base64_encode($content))." | base64 -d > \"\$R/{$name}\"";
            }
        }

        $env = [
            'HOME' => $this->git->runPath(),
            'GIT_CONFIG_NOSYSTEM' => '1',
            'GIT_CONFIG_GLOBAL' => '/dev/null',
            'GIT_TERMINAL_PROMPT' => '0',
            'GIT_ASKPASS' => '/bin/false',
            'SSH_ASKPASS' => '/bin/false',
            'GIT_NO_LAZY_FETCH' => '1',
            'GIT_LITERAL_PATHSPECS' => '1',
        ];
        foreach ($this->access->env as $name => $value) {
            if (preg_match('/\AGIT_[A-Z_]+\z/', $name) === 1) {
                $env[$name] = str_replace(GitAccess::MOUNT, $this->git->runPath(), $value);
            }
        }
        $config = array_values(array_filter($this->access->config, fn (string $entry): bool => str_contains($entry, '=')));
        $env['GIT_CONFIG_COUNT'] = (string) count($config);
        foreach ($config as $index => $entry) {
            [$key, $value] = explode('=', $entry, 2);
            $env["GIT_CONFIG_KEY_{$index}"] = $key;
            $env["GIT_CONFIG_VALUE_{$index}"] = $value;
        }
        foreach ($env as $name => $value) {
            $lines[] = "export {$name}=".self::arg($value);
        }

        $protocols = ['https', 'http', 'ssh', 'git', ...($this->git->allowsFileProtocol() ? ['file'] : [])];
        $allow = implode(' ', array_map(fn (string $protocol): string => "-c protocol.{$protocol}.allow=always", $protocols));
        array_push($lines,
            "gitc() { git -C \"\$G\" -c protocol.allow=never {$allow} -c core.hooksPath=/dev/null -c http.lowSpeedLimit=1000 -c http.lowSpeedTime=60 -c fetch.recurseSubmodules=false -c submodule.recurse=false -c gc.autoDetach=false -c maintenance.autoDetach=false \"\$@\"; }",
            'fetch_quiet() { gitc fetch -q --no-tags --no-write-fetch-head --filter=blob:none origin "$@" 2>> "$R/err"; }',
            'have_commit() { gitc cat-file -e "$1^{commit}" 2>/dev/null || { fetch_quiet "$1" && gitc cat-file -e "$1^{commit}" 2>/dev/null; }; }',
            'diff_from() { if have_commit "$2" && gitc diff-tree -r -z --no-renames "$2" "$AFTER" > "$R/diff" 2>> "$R/err"; then printf "@@DIFF %s %s " "$1" "$2"; payload "$R/diff"; else printf "@@DIFF %s %s -\n" "$1" "$2"; fi; }',
            'check_size() { if [ "$(du -sk "$G" | cut -f1)" -gt '.intdiv($this->maxMirrorBytes, 1024).' ]; then rm -rf "$G"; fail "The repository mirror is larger than the limit, so it was removed."; fi; }',
            'prefetch() { gitc cat-file --batch-check < "$1" 2>/dev/null | awk \'$2 == "missing" { print $1 }\' > "$R/missing"; if [ -s "$R/missing" ]; then gitc -c fetch.negotiationAlgorithm=noop fetch -q --no-tags --no-write-fetch-head --filter=blob:none --stdin origin < "$R/missing" 2>> "$R/err" || fail "git fetch of file contents failed"; check_size; fi; }',
            'blobs() { prefetch "$1"; gitc cat-file --batch < "$1" > "$R/blobs" 2>> "$R/err" || fail "git cat-file failed"; printf "@@BLOBS "; payload "$R/blobs"; }',
            'exec 9> "$G.lock"',
            'WAITED=0; until flock -n 9; do WAITED=$((WAITED + 1)); [ "$WAITED" -lt '.self::LOCK_WAIT_SECONDS.' ] || fail "The repository mirror is busy."; sleep 1; done',
            // With the lock held no git process uses the mirror: lock files and temporary packs are left by a killed run.
            'if [ -d "$G" ]; then find "$G" -type f -name "*.lock" -exec rm -f {} + 2>/dev/null; find "$G/objects" -type f -name "tmp_*" -exec rm -f {} + 2>/dev/null; fi',
            'if [ ! -f "$G/HEAD" ]; then rm -rf "$G"; git init -q --bare "$G" 2>> "$R/err" || fail "git init failed"; fi',
            'gitc config core.repositoryformatversion 1 && gitc config extensions.partialClone origin && gitc config remote.origin.promisor true && gitc config remote.origin.partialclonefilter blob:none || fail "git config failed"',
            'gitc config remote.origin.url '.self::arg($this->access->repositoryUrl).' || fail "git config failed"',
            'touch "$G" "$G.lock"',
        );

        return implode("\n", [...$lines, ...$body, 'echo "@@END"'])."\n";
    }

    private static function arg(string $value): string
    {
        return escapeshellarg($value);
    }

    /**
     * Writes one line per item to a file with a quoted here-document, which has no length limit unlike an argument.
     *
     * @param  list<string>  $lines
     */
    private static function file(string $path, array $lines): string
    {
        $content = implode("\n", $lines)."\n";
        do {
            $delimiter = 'COOLIFY_'.bin2hex(random_bytes(8));
        } while (str_contains($content, $delimiter));

        return "cat > {$path} <<'{$delimiter}'\n{$content}{$delimiter}";
    }
}
