<?php

namespace App\Domain\Remote\Commands;

class RemoteCommand
{
    // save example:   'save' => 'git_commit_sha', 'save' => 'local_image_found','save' => 'dockerfile_from_repo',  'save' => 'nixpacks_type',
    // 'save' => 'post-deployment-command-output',
    // ignore errors example: 'ignore_errors' => true,
    // append example:   'append' => false,
    // type example:  'type' => 'stderr',
    public function __construct(public string $command, public bool $hidden = false,
        public ?string $save = null, public ?bool $ignoreErrors = false,
        public ?bool $append = false, public ?string $type = null) {}

    public function shouldSave(): bool
    {
        return strlen($this->save) > 0;
    }
}
