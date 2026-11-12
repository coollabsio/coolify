<?php

namespace App\Actions\Node\Build;

use App\Services\Security\SensitiveDataRedactor;
use Illuminate\Support\Facades\Process;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Resolves the branch of a Git repository to its commit SHA. Every attempt of
 * one build then uses the same source. A private repository is read with the
 * credential of {@see IssueNodeGitCredential}: the token comes from an
 * environment variable through a credential helper, the deploy key from a
 * temporary file. Neither is in the URL or the arguments.
 */
class ResolveGitCommit
{
    use AsAction;

    /** @param  array{git_https_token?: string, git_ssh_private_key?: string}  $credential */
    public function handle(string $repository, string $branch, array $credential = []): string
    {
        $config = [];
        $env = ['GIT_TERMINAL_PROMPT' => '0', 'GIT_ASKPASS' => '/bin/false', 'SSH_ASKPASS' => '/bin/false'];
        $keyFile = null;
        try {
            if (isset($credential['git_https_token'])) {
                $origin = self::httpsOrigin($repository);
                $env['COOLIFY_GIT_TOKEN'] = $credential['git_https_token'];
                // An empty helper first drops the helpers of the system and global config.
                array_push($config,
                    '-c', "credential.{$origin}.helper=",
                    '-c', "credential.{$origin}.helper=!f() { echo username=x-access-token; echo \"password=\$COOLIFY_GIT_TOKEN\"; }; f",
                );
            } elseif (isset($credential['git_ssh_private_key'])) {
                $keyFile = tempnam(sys_get_temp_dir(), 'coolify-git-key-');
                if ($keyFile === false || ! chmod($keyFile, 0600) || file_put_contents($keyFile, rtrim($credential['git_ssh_private_key'])."\n") === false) {
                    throw new RuntimeException('Coolify cannot prepare the deploy key.');
                }
                $env['GIT_SSH_COMMAND'] = 'ssh -i '.escapeshellarg($keyFile).' -o IdentitiesOnly=yes -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o BatchMode=yes -o ConnectTimeout=20';
            }

            // A neutral directory: git reads a `.git` in the working directory even for ls-remote.
            $result = Process::path(sys_get_temp_dir())
                ->timeout(30)
                ->env($env)
                ->run(['git', ...$config, 'ls-remote', '--', $repository, 'refs/heads/'.$branch]);
        } finally {
            if (is_string($keyFile) && file_exists($keyFile)) {
                unlink($keyFile);
            }
        }
        if ($result->failed()) {
            $reason = trim(mb_substr(app(SensitiveDataRedactor::class)->redactText($result->errorOutput(), lockedSecrets: array_values($credential)), 0, 500));
            $hint = $credential === []
                ? 'Check that it is public and the URL is correct.'
                : 'Check the URL and that the '.(isset($credential['git_https_token']) ? 'GitHub App can access the repository.' : 'deploy key is added to the repository.');
            throw new RuntimeException("Coolify cannot read the repository. {$hint}".($reason !== '' ? " Git said: {$reason}" : ''));
        }
        foreach (preg_split('/\R/', trim($result->output())) ?: [] as $line) {
            if (preg_match('/^([0-9a-f]{40}|[0-9a-f]{64})\trefs\/heads\/(.+)$/', $line, $match) === 1 && $match[2] === $branch) {
                return $match[1];
            }
        }

        throw new RuntimeException("The branch {$branch} does not exist in the repository.");
    }

    /** `https://github.com/acme/web.git` becomes `https://github.com`, the scope of the credential helper. */
    private static function httpsOrigin(string $repository): string
    {
        $parts = parse_url($repository);
        if (($parts['scheme'] ?? null) !== 'https' || blank($parts['host'] ?? null) || isset($parts['user']) || isset($parts['pass'])) {
            throw new RuntimeException('A token needs an https:// repository URL without user info.');
        }

        return 'https://'.$parts['host'].(isset($parts['port']) ? ':'.$parts['port'] : '');
    }
}
