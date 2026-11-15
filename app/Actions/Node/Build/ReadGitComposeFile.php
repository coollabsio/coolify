<?php

namespace App\Actions\Node\Build;

use App\Models\Application;
use App\Services\NodeStack\NodeStackGitSource;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Lorisleiva\Actions\Concerns\AsAction;
use RuntimeException;

/**
 * Reads the Compose file of a Compose application from Git in the Coolify container (step 2 of
 * decision 0023). It fetches only the head commit of the branch and its trees, without file
 * content, then reads the one file. A symbolic link and a file larger than 5 MiB are refused, as
 * v4 does. A private repository uses the credential of {@see IssueNodeGitCredential}.
 */
class ReadGitComposeFile
{
    use AsAction;

    /**
     * @param  array{git_https_token?: string, git_ssh_private_key?: string}  $credential
     * @return array{commit_sha: string, content: string, path: string}
     */
    public function handle(string $repository, string $branch, array $credential, ?string $baseDirectory, ?string $composeLocation): array
    {
        $path = NodeStackGitSource::repositoryPath($baseDirectory, $composeLocation ?: NodeStackGitSource::DEFAULT_COMPOSE_LOCATION);
        if ($path === '') {
            throw new RuntimeException('Enter the location of the Compose file.');
        }
        $directory = sys_get_temp_dir().'/coolify-compose-'.Str::random(16);
        if (! mkdir($directory, 0700)) {
            throw new RuntimeException('Coolify cannot prepare a directory to read the repository.');
        }

        try {
            return NodeGitProcess::run($repository, $credential, function ($git) use ($repository, $branch, $credential, $path, $directory): array {
                foreach ([['init', '--quiet'], ['remote', 'add', 'origin', '--', $repository]] as $arguments) {
                    $result = $git($arguments, $directory, 30);
                    if ($result->failed()) {
                        throw new RuntimeException('Coolify cannot prepare the repository: '.trim(mb_substr($result->errorOutput(), 0, 300)));
                    }
                }
                $fetch = $git(['fetch', '--quiet', '--depth', '1', '--filter=blob:none', '--no-tags', 'origin', 'refs/heads/'.$branch], $directory, 120);
                if ($fetch->failed()) {
                    if (str_contains($fetch->errorOutput(), "couldn't find remote ref")) {
                        throw new RuntimeException("The branch {$branch} does not exist in the repository.");
                    }
                    throw NodeGitProcess::readError($fetch, $credential);
                }
                $commit = trim($git(['rev-parse', 'FETCH_HEAD'], $directory, 10)->output());
                if (preg_match('/\A([0-9a-f]{40}|[0-9a-f]{64})\z/', $commit) !== 1) {
                    throw new RuntimeException("Coolify cannot read the commit of the branch {$branch}.");
                }

                // `<mode> <type> <object>\t<path>`; ls-tree does not fetch the content.
                $entry = trim($git(['ls-tree', '--full-tree', $commit, '--', $path], $directory, 10)->output());
                if (preg_match('/\A(\d{6}) (\w+) ([0-9a-f]{40,64})\t/', $entry, $match) !== 1) {
                    throw new RuntimeException("The Compose file /{$path} does not exist in the branch {$branch}.");
                }
                if ($match[1] === '120000') {
                    throw new RuntimeException("The Compose file /{$path} is a symbolic link. Enter the location of the file itself.");
                }
                if ($match[2] !== 'blob') {
                    throw new RuntimeException("/{$path} is not a file.");
                }
                // Fetches the one file from the remote.
                $size = $git(['cat-file', '-s', $match[3]], $directory, 60);
                if ($size->failed()) {
                    throw NodeGitProcess::readError($size, $credential);
                }
                if ((int) trim($size->output()) > Application::MAX_DOCKER_COMPOSE_SIZE_BYTES) {
                    throw new RuntimeException("The Compose file /{$path} is larger than 5 MiB.");
                }
                $content = $git(['cat-file', 'blob', $match[3]], $directory, 30);
                if ($content->failed()) {
                    throw NodeGitProcess::readError($content, $credential);
                }

                return ['commit_sha' => $commit, 'content' => $content->output(), 'path' => $path];
            });
        } finally {
            File::deleteDirectory($directory);
        }
    }
}
