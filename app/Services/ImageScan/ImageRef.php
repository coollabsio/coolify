<?php

namespace App\Services\ImageScan;

use App\Rules\DockerImageFormat;
use InvalidArgumentException;
use Stringable;

/**
 * A validated image reference, split by the Docker reference grammar: the first path component is a
 * registry only when it contains '.' or ':' or is 'localhost'; Docker Hub names get `library/`.
 */
final class ImageRef implements Stringable
{
    private function __construct(
        public readonly string $registry,
        public readonly string $repository,
        public readonly ?string $tag,
        public readonly ?string $digest,
    ) {}

    public static function parse(string $ref): self
    {
        $failed = $ref === '';
        (new DockerImageFormat)->validate('image', $ref, function () use (&$failed): void {
            $failed = true;
        });
        if ($failed) {
            throw new InvalidArgumentException('Invalid image reference.');
        }

        $name = $ref;
        $tag = null;
        $digest = null;
        if (str_contains($ref, '@')) {
            [$name, $digest] = explode('@', $ref, 2);
            if (! self::isValidDigest($digest)) {
                throw new InvalidArgumentException('Invalid image digest.');
            }
        } else {
            $lastColon = strrpos($ref, ':');
            $lastSlash = strrpos($ref, '/');
            if ($lastColon !== false && ($lastSlash === false || $lastColon > $lastSlash)) {
                $name = substr($ref, 0, $lastColon);
                $tag = substr($ref, $lastColon + 1);
                if (! self::isValidTag($tag)) {
                    throw new InvalidArgumentException('Invalid image tag.');
                }
            }
        }

        $registry = 'docker.io';
        $repository = $name;
        $parts = explode('/', $name, 2);
        if (count($parts) === 2 && (str_contains($parts[0], '.') || str_contains($parts[0], ':') || $parts[0] === 'localhost')) {
            [$registry, $repository] = $parts;
        }
        if ($registry === 'index.docker.io') {
            $registry = 'docker.io';
        }
        if ($registry === 'docker.io' && ! str_contains($repository, '/')) {
            $repository = 'library/'.$repository;
        }

        return new self($registry, $repository, $tag, $digest);
    }

    public static function isValidTag(string $tag): bool
    {
        return preg_match('/\A[A-Za-z0-9_][A-Za-z0-9._-]{0,127}\z/', $tag) === 1;
    }

    public static function isValidDigest(string $digest): bool
    {
        return preg_match('/\Asha256:[a-f0-9]{64}\z/', $digest) === 1;
    }

    public function isDockerOfficial(): bool
    {
        return $this->registry === 'docker.io' && preg_match('#\Alibrary/[^/]+\z#', $this->repository) === 1;
    }

    public function withTag(string $tag): self
    {
        if (! self::isValidTag($tag)) {
            throw new InvalidArgumentException('Invalid image tag.');
        }

        return new self($this->registry, $this->repository, $tag, null);
    }

    /**
     * Registry and repository without tag or digest, for example `docker.io/library/nginx`.
     */
    public function qualifiedName(): string
    {
        return $this->registry.'/'.$this->repository;
    }

    /**
     * The short form Docker shows (`nginx:1.27`, `grafana/grafana`, `ghcr.io/a/b@sha256:…`).
     */
    public function __toString(): string
    {
        $name = match (true) {
            $this->isDockerOfficial() => substr($this->repository, strlen('library/')),
            $this->registry === 'docker.io' => $this->repository,
            default => $this->qualifiedName(),
        };

        return match (true) {
            $this->digest !== null => $name.'@'.$this->digest,
            $this->tag !== null => $name.':'.$this->tag,
            default => $name,
        };
    }
}
