<?php

namespace App\Actions\Node\Build;

use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Resolves the branch of a Git repository to its commit SHA. Every attempt of
 * one build then uses the same source. A private repository is read with the
 * credential of {@see IssueNodeGitCredential} through {@see NodeGitProcess}.
 */
class ResolveGitCommit
{
    use AsAction;

    /** @param  array{git_https_token?: string, git_ssh_private_key?: string}  $credential */
    public function handle(string $repository, string $branch, array $credential = []): string
    {
        // A neutral directory: git reads a `.git` in the working directory even for ls-remote.
        $result = NodeGitProcess::run($repository, $credential, fn ($git) => $git(['ls-remote', '--', $repository, 'refs/heads/'.$branch], sys_get_temp_dir(), 30));
        if ($result->failed()) {
            throw NodeGitProcess::readError($result, $credential);
        }
        foreach (preg_split('/\R/', trim($result->output())) ?: [] as $line) {
            if (preg_match('/^([0-9a-f]{40}|[0-9a-f]{64})\trefs\/heads\/(.+)$/', $line, $match) === 1 && $match[2] === $branch) {
                return $match[1];
            }
        }

        throw new RuntimeException("The branch {$branch} does not exist in the repository.");
    }
}
