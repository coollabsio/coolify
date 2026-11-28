<?php

use App\Services\DeployTriggers\Contract\AffectedContractException;
use App\Services\DeployTriggers\Contract\EvaluateRequest;
use App\Services\DeployTriggers\Git\MirrorGit;
use App\Services\DeployTriggers\Git\MirrorOutput;
use App\Services\DeployTriggers\Git\MirrorSession;
use App\Services\DeployTriggers\GitAccess;

/**
 * @param  list<string>  $outputs  one script output per run, in order
 */
function cannedMirrorSession(array $outputs): MirrorSession
{
    $git = new class($outputs) implements MirrorGit
    {
        public function __construct(private array $outputs) {}

        public function run(string $script, int $timeoutSeconds): string
        {
            return array_shift($this->outputs) ?? "@@END\n";
        }

        public function mirrorsPath(): string
        {
            return '/mirrors';
        }

        public function runPath(): string
        {
            return '/run/coolify';
        }

        public function allowsFileProtocol(): bool
        {
            return false;
        }
    };

    return new MirrorSession($git, new GitAccess('https://example.com/acme/mono.git'), new EvaluateRequest('https://example.com/acme/mono.git', 'key', str_repeat('a', 40), ['refs/heads/main'], []), fn (string $message) => $message);
}

function cannedPayload(string $data): string
{
    return base64_encode(gzencode($data));
}

it('rejects an empty payload', function () {
    MirrorOutput::payload('');
})->throws(AffectedContractException::class);

it('fails when the tree of the commit is missing from the output', function () {
    cannedMirrorSession(['@@BLOBS '.cannedPayload('')."\n@@END\n"])->snapshot(str_repeat('a', 40), 1000);
})->throws(AffectedContractException::class);

it('fails when a requested file content is missing', function () {
    $oid = str_repeat('c', 40);
    $tree = "100644 blob {$oid}\tsrc/index.ts\0";
    $session = cannedMirrorSession([
        '@@TREE '.cannedPayload($tree)."\n@@BLOBS ".cannedPayload('')."\n@@END\n",
        '@@BLOBS '.cannedPayload("{$oid} missing\n")."\n@@END\n",
    ]);

    $session->snapshot(str_repeat('a', 40), 1000)->read('src/index.ts');
})->throws(AffectedContractException::class);
