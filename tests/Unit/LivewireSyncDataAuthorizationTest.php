<?php

use PhpParser\Node;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\Node\Stmt\If_;
use PhpParser\Node\Stmt\Return_;
use PhpParser\Node\Stmt\TryCatch;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/** @return list<array{path: string, method: ClassMethod}> */
function livewireSyncDataMethods(string $directory): array
{
    $methods = [];
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory));
    $parser = (new ParserFactory)->createForNewestSupportedVersion();
    $finder = new NodeFinder;

    foreach ($iterator as $file) {
        if (! $file->isFile() || $file->getExtension() !== 'php') {
            continue;
        }
        $path = $file->getPathname();
        $contents = file_get_contents($path);
        if ($contents === false) {
            continue;
        }
        foreach ($finder->findInstanceOf($parser->parse($contents) ?? [], ClassMethod::class) as $method) {
            if (preg_match('/^sync(Data|DatabaseData|ApplicationData)/', $method->name->toString())) {
                $methods[] = ['path' => $path, 'method' => $method];
            }
        }
    }

    usort($methods, fn (array $a, array $b): int => [$a['path'], $a['method']->name->toString()] <=> [$b['path'], $b['method']->name->toString()]);

    return $methods;
}

function livewireMethodCallName(MethodCall $call): ?string
{
    return $call->name instanceof Identifier ? $call->name->toString() : null;
}

function livewireIsWriteSyncCall(MethodCall $call, string $syncMethod): bool
{
    if (livewireMethodCallName($call) !== $syncMethod) {
        return false;
    }
    foreach ($call->args as $position => $argument) {
        if (($position === 0 || $argument->name?->toString() === 'toModel') && $argument->value instanceof ConstFetch && $argument->value->name->toLowerString() === 'true') {
            return true;
        }
    }

    return false;
}

/** @param list<Node\Stmt> $statements @param list<int> $unauthorizedLines */
function livewireScanStatements(array $statements, string $syncMethod, bool $authorized, array &$unauthorizedLines): bool
{
    foreach ($statements as $statement) {
        $authorized = livewireScanNode($statement, $syncMethod, $authorized, $unauthorizedLines);
    }

    return $authorized;
}

/** @param list<int> $unauthorizedLines */
function livewireScanNode(mixed $node, string $syncMethod, bool $authorized, array &$unauthorizedLines): bool
{
    if (! $node instanceof Node && ! is_array($node)) {
        return $authorized;
    }
    if (is_array($node)) {
        return livewireScanStatements($node, $syncMethod, $authorized, $unauthorizedLines);
    }
    if ($node instanceof TryCatch) {
        $tryAuthorized = livewireScanStatements($node->stmts, $syncMethod, $authorized, $unauthorizedLines);
        $allCatchesTerminate = $node->catches !== [];
        foreach ($node->catches as $catch) {
            livewireScanStatements($catch->stmts, $syncMethod, $authorized, $unauthorizedLines);
            $allCatchesTerminate = $allCatchesTerminate && collect($catch->stmts)->contains(fn (Node\Stmt $statement): bool => $statement instanceof Return_);
        }
        if ($node->finally !== null) {
            livewireScanStatements($node->finally->stmts, $syncMethod, $authorized, $unauthorizedLines);
        }

        return $allCatchesTerminate ? $tryAuthorized : $authorized;
    }
    if ($node instanceof If_) {
        livewireScanNode($node->cond, $syncMethod, $authorized, $unauthorizedLines);
        livewireScanStatements($node->stmts, $syncMethod, $authorized, $unauthorizedLines);
        foreach ($node->elseifs as $elseif) {
            livewireScanNode($elseif->cond, $syncMethod, $authorized, $unauthorizedLines);
            livewireScanStatements($elseif->stmts, $syncMethod, $authorized, $unauthorizedLines);
        }
        if ($node->else !== null) {
            livewireScanStatements($node->else->stmts, $syncMethod, $authorized, $unauthorizedLines);
        }

        return $authorized;
    }
    if ($node instanceof MethodCall) {
        $name = livewireMethodCallName($node);
        if ($name === 'authorize') {
            return true;
        }
        if (livewireIsWriteSyncCall($node, $syncMethod) && ! $authorized) {
            $unauthorizedLines[] = $node->getStartLine();
        }
    }
    foreach ($node->getSubNodeNames() as $name) {
        $authorized = livewireScanNode($node->{$name}, $syncMethod, $authorized, $unauthorizedLines);
    }

    return $authorized;
}

/** @return list<int> */
function livewireUnauthorizedSyncWriteLines(ClassMethod $method, string $syncMethod): array
{
    $lines = [];
    livewireScanStatements($method->stmts ?? [], $syncMethod, false, $lines);

    return $lines;
}

function livewireMethodFromSource(string $source, string $method): ClassMethod
{
    $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($source) ?? [];

    return (new NodeFinder)->findFirst($nodes, fn (Node $node): bool => $node instanceof ClassMethod && $node->name->toString() === $method);
}

it('keeps every Livewire syncData helper private', function () {
    $violations = [];
    foreach (livewireSyncDataMethods(dirname(__DIR__, 2).'/app/Livewire') as $syncMethod) {
        if (! $syncMethod['method']->isPrivate()) {
            $relative = str_replace(dirname(__DIR__, 2).'/', '', $syncMethod['path']);
            $violations[] = "{$relative}::{$syncMethod['method']->name}() is not private";
        }
    }
    expect($violations)->toBeEmpty("Livewire syncData helpers must be private:\n".implode("\n", $violations));
});

it('discovers files that only define syncApplicationData helpers', function () {
    $directory = sys_get_temp_dir().'/livewire-sync-'.uniqid();
    mkdir($directory);
    file_put_contents($directory.'/Example.php', '<?php class Example { private function syncApplicationData(bool $toModel = false) {} }');

    $methods = livewireSyncDataMethods($directory);

    unlink($directory.'/Example.php');
    rmdir($directory);

    expect($methods)->toHaveCount(1)
        ->and($methods[0]['method']->name->toString())->toBe('syncApplicationData');
});

it('detects named write arguments and does not carry authorization into catch paths', function () {
    $method = livewireMethodFromSource(<<<'PHP'
        <?php
        class Example {
            public function instantSave() {
                try {
                    $this->authorize('update', $this->resource);
                    $this->syncData(toModel: true);
                } catch (Throwable $exception) {
                    $this->syncData(toModel: true);
                }
            }
        }
        PHP, 'instantSave');

    expect(livewireUnauthorizedSyncWriteLines($method, 'syncData'))->toHaveCount(1);
});

it('authorizes before every syncData write call', function () {
    $violations = [];
    $root = dirname(__DIR__, 2);
    foreach (livewireSyncDataMethods($root.'/app/Livewire') as $syncMethod) {
        $contents = file_get_contents($syncMethod['path']);
        $nodes = (new ParserFactory)->createForNewestSupportedVersion()->parse($contents) ?? [];
        foreach ((new NodeFinder)->findInstanceOf($nodes, ClassMethod::class) as $caller) {
            foreach (livewireUnauthorizedSyncWriteLines($caller, $syncMethod['method']->name->toString()) as $line) {
                $relative = str_replace($root.'/', '', $syncMethod['path']);
                $violations[] = "{$relative}:{$line}::{$caller->name}() calls {$syncMethod['method']->name}(true) without prior authorization";
            }
        }
    }
    expect($violations)->toBeEmpty("Missing authorization before syncData write calls:\n".implode("\n", array_unique($violations)));
});
