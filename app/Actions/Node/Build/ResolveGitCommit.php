<?php

namespace App\Actions\Node\Build;

use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Resolves a branch, or a full ref such as `refs/pull/12/head` of a preview (decision 0025), of
 * a Git repository to its commit SHA. Every attempt of one build then uses the same source. A
 * private repository is read with the credential of {@see IssueNodeGitCredential} through
 * {@see NodeGitProcess}.
 */
class ResolveGitCommit
{
    use AsAction;

    /** @param  array{git_https_token?: string, git_ssh_private_key?: string}  $credential */
    public function handle(string $repository, string $branchOrRef, array $credential = []): string
    {
        $ref = str_starts_with($branchOrRef, 'refs/') ? $branchOrRef : 'refs/heads/'.$branchOrRef;
        // A neutral directory: git reads a `.git` in the working directory even for ls-remote.
        $result = NodeGitProcess::run($repository, $credential, fn ($git) => $git(['ls-remote', '--', $repository, $ref], sys_get_temp_dir(), 30));
        if ($result->failed()) {
            throw NodeGitProcess::readError($result, $credential);
        }
        foreach (preg_split('/\R/', trim($result->output())) ?: [] as $line) {
            if (preg_match('/^([0-9a-f]{40}|[0-9a-f]{64})\t(.+)$/', $line, $match) === 1 && $match[2] === $ref) {
                return $match[1];
            }
        }

        throw new RuntimeException($ref === $branchOrRef
            ? "The ref {$ref} does not exist in the repository."
            : "The branch {$branchOrRef} does not exist in the repository.");
    }
}
