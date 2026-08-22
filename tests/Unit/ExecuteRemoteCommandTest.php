<?php

use App\Traits\ExecuteRemoteCommand;
use Illuminate\Support\Collection;

require_once __DIR__.'/../../app/Traits/ExecuteRemoteCommand.php';

function remoteCommandOutputCollector(): object
{
    return new class
    {
        use ExecuteRemoteCommand;

        public Collection $saved_outputs;

        public function __construct()
        {
            $this->save = 'dockerfile';
            $this->saved_outputs = collect();
        }

        public function collectOutput(string $output, bool $append = true): void
        {
            $this->saveCommandOutput($output, $append);
        }
    };
}

it('preserves whitespace across streamed saved output chunks', function () {
    $collector = remoteCommandOutputCollector();

    foreach (["FROM alpine\nARG FIRST", "\n", 'ARG', ' ', "SECOND\nRUN true\n"] as $chunk) {
        $collector->collectOutput($chunk);
    }

    expect((string) $collector->saved_outputs->get('dockerfile'))
        ->toBe("FROM alpine\nARG FIRST\nARG SECOND\nRUN true\n");
});

it('replaces saved output without trimming it when append is disabled', function () {
    $collector = remoteCommandOutputCollector();
    $collector->collectOutput('old');

    $collector->collectOutput(" new output\n", append: false);

    expect((string) $collector->saved_outputs->get('dockerfile'))->toBe(" new output\n");
});
