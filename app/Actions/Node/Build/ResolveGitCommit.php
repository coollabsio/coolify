<?php

namespace App\Actions\Node\Build;

use Illuminate\Support\Facades\Process;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Resolves the branch of a public Git repository to its commit SHA. Every
 * attempt of one build then uses the same source.
 */
class ResolveGitCommit
{
    use AsAction;

    public function handle(string $repository, string $branch): string
    {
        // A neutral directory: git reads a `.git` in the working directory even for ls-remote.
        $result = Process::path(sys_get_temp_dir())
            ->timeout(30)
            ->env(['GIT_TERMINAL_PROMPT' => '0', 'GIT_ASKPASS' => '/bin/false'])
            ->run(['git', 'ls-remote', '--', $repository, 'refs/heads/'.$branch]);
        if ($result->failed()) {
            $reason = trim(mb_substr($result->errorOutput(), 0, 500));
            throw new RuntimeException('Coolify cannot read the repository. Check that it is public and the URL is correct.'.($reason !== '' ? " Git said: {$reason}" : ''));
        }
        foreach (preg_split('/\R/', trim($result->output())) ?: [] as $line) {
            if (preg_match('/^([0-9a-f]{40}|[0-9a-f]{64})\trefs\/heads\/(.+)$/', $line, $match) === 1 && $match[2] === $branch) {
                return $match[1];
            }
        }

        throw new RuntimeException("The branch {$branch} does not exist in the repository.");
    }
}
