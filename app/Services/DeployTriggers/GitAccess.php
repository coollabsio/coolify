<?php

namespace App\Services\DeployTriggers;

/**
 * How the git mirror reaches a repository from a helper container. Files are written to a tmpfs at /run/coolify.
 */
final readonly class GitAccess
{
    public const MOUNT = '/run/coolify';

    /**
     * @param  array<string, string>  $env
     * @param  list<string>  $config  `git -c` values; may hold a token
     * @param  array<string, string>  $files  file name => content
     */
    public function __construct(
        public string $repositoryUrl,
        public array $env = [],
        public array $config = [],
        public array $files = [],
    ) {}

    /**
     * @param  list<string>  $knownHostsLines
     * @param  list<string>  $pinnedHosts
     */
    public static function ssh(string $repositoryUrl, string $privateKey, int|string|null $port, array $knownHostsLines, array $pinnedHosts): self
    {
        $mount = self::MOUNT;
        $config = implode("\n", [
            'Host '.implode(' ', $pinnedHosts),
            '    StrictHostKeyChecking yes',
            'Host *',
            '    StrictHostKeyChecking accept-new',
            "    UserKnownHostsFile {$mount}/known_hosts",
            '    HashKnownHosts no',
        ])."\n";
        $command = "ssh -F {$mount}/ssh_config -o ConnectTimeout=30 -o ServerAliveInterval=15 -o ServerAliveCountMax=4";
        if (filled($port)) {
            $command .= " -p {$port} -o Port={$port}";
        }
        $command .= " -o LogLevel=ERROR -i {$mount}/id_key -o IdentitiesOnly=yes";

        return new self(
            repositoryUrl: $repositoryUrl,
            env: ['GIT_SSH_COMMAND' => $command],
            files: [
                'id_key' => rtrim($privateKey, "\n")."\n",
                'known_hosts' => implode("\n", $knownHostsLines)."\n",
                'ssh_config' => $config,
            ],
        );
    }

    /**
     * Safe to log: no token and no key material.
     *
     * @return array{repository_url: string, env: array<string, string>, config_count: int, files: list<string>}
     */
    public function redacted(): array
    {
        return [
            'repository_url' => $this->repositoryUrl,
            'env' => $this->env,
            'config_count' => count($this->config),
            'files' => array_keys($this->files),
        ];
    }
}
