<?php

namespace App\Services\ProjectConfig;

use App\Models\Application;
use App\Models\ApplicationSetting;
use App\Models\EnvironmentConfigLink;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Reads one file of a linked repository at one commit, on the read server of the link.
 *
 * The clone has no checkout. The file is read from the Git objects and printed as base64, so its bytes
 * stay exact. Only regular files are read.
 */
class RepositoryFileReader
{
    public const MISSING = '__COOLIFY_CONFIG_MISSING__';

    public const TOO_LARGE = '__COOLIFY_CONFIG_TOO_LARGE__';

    public function read(EnvironmentConfigLink $link, ?string $commit = null): RepositoryFile
    {
        $server = $link->readServer;
        if ($server === null) {
            throw new RepositoryFileException('The link has no server to read the repository.');
        }
        if ($server->isTransferredAway()) {
            throw new RepositoryFileException("Server \"{$server->name}\" was transferred to another Coolify instance.");
        }

        $uuid = 'cfg-'.new_public_id();
        try {
            $output = instant_remote_process($this->commands($link, $uuid, $commit), $server, maxAttempts: 3);
        } catch (Throwable $e) {
            throw new RepositoryFileException($e->getMessage(), 0, $e);
        } finally {
            rescue(fn () => instant_remote_process(["rm -rf /tmp/{$uuid}"], $server, throwError: false), report: false);
        }

        return $this->parse((string) $output);
    }

    /**
     * @return Collection<int, string>
     */
    public function commands(EnvironmentConfigLink $link, string $uuid, ?string $commit = null): Collection
    {
        $checkoutDir = "/tmp/{$uuid}/checkout";
        $clone = $this->checkoutApplication($link)->serverCheckoutCommand($uuid, $checkoutDir, $commit);
        $read = implode(' ', [
            'file='.escapeshellarg(ltrim((string) $link->file_path, '/')).';',
            'git rev-parse HEAD || exit 1;',
            'git ls-tree HEAD -- "$file" | {',
            'read -r mode type object path;',
            'case "$mode" in',
            '100644|100755) ;;',
            "'') echo '".self::MISSING."'; exit 0 ;;",
            "120000) echo 'coolify.json is a symbolic link. Use a regular file.' >&2; exit 1 ;;",
            "*) echo 'coolify.json is not a regular file.' >&2; exit 1 ;;",
            'esac;',
            'if [ "$(git cat-file -s "$object")" -gt '.ConfigParser::MAX_BYTES." ]; then echo '".self::TOO_LARGE."';",
            "else git cat-file blob \"\$object\" | base64 | tr -d '\\n'; echo; fi;",
            '}',
        ]);

        return collect([
            "rm -rf /tmp/{$uuid}",
            "mkdir -p /tmp/{$uuid}",
            "cd /tmp/{$uuid}",
            'sh -c '.escapeshellarg($clone),
            "cd {$checkoutDir}",
            'sh -c '.escapeshellarg($read),
        ]);
    }

    public function parse(string $output): RepositoryFile
    {
        $lines = preg_split('/\R/', trim($output));
        $sha = trim($lines[0] ?? '');
        $payload = trim($lines[1] ?? '');
        if (! preg_match('/^[0-9a-f]{40,64}$/', $sha)) {
            throw new RepositoryFileException('Could not read the commit of the repository.');
        }
        if ($payload === self::MISSING) {
            return new RepositoryFile(null, $sha, true);
        }
        if ($payload === self::TOO_LARGE) {
            throw new RepositoryFileException('coolify.json is larger than 1 MiB.');
        }
        $contents = base64_decode($payload, true);
        if ($contents === false) {
            throw new RepositoryFileException('Could not read coolify.json.');
        }

        return new RepositoryFile($contents, $sha, false);
    }

    /**
     * An unsaved application with the git settings of the link, to build the same clone command as a deployment.
     */
    private function checkoutApplication(EnvironmentConfigLink $link): Application
    {
        $application = new Application;
        $application->forceFill([
            'git_repository' => $link->repository,
            'git_branch' => $link->branch,
            'git_commit_sha' => 'HEAD',
            'source_type' => $link->source_type,
            'source_id' => $link->source_id,
            'private_key_id' => $link->private_key_id,
            'base_directory' => '/',
        ]);
        $application->setRelation('settings', new ApplicationSetting([
            'is_git_shallow_clone_enabled' => true,
            'is_git_submodules_enabled' => false,
            'is_git_lfs_enabled' => false,
        ]));
        if ($link->source_type !== null) {
            $application->setRelation('source', $link->source);
        }

        return $application;
    }
}
